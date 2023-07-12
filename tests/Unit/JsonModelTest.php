<?php

/**
 * Test our JsonModel. Make sure it basically works like a model (attributes, save, update) and optionally validates when a schema exists
 */

declare(strict_types=1);

namespace Tests\Unit;

use Carsdotcom\JsonSchemaValidation\Exceptions\JsonSchemaValidationException;
use Carsdotcom\LaravelJsonModel\CollectionOfJsonModels;
use Carsdotcom\JsonSchemaValidation\SchemaValidator as SchemaValidator;
use Carsdotcom\LaravelJsonModel\JsonModel;
use Carsdotcom\LaravelJsonModel\Traits\HasJsonModelAttributes;
use Carbon\Carbon;
use Carsdotcom\LaravelJsonModel\Traits\NullWhenUsedAsAttributeWhenEmpty;
use DomainException;
use Exception;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;
use Mockery;
use RuntimeException;
use stdClass;
use Tests\BaseTestCase;
use Tests\MockClasses\ConcreteJsonModel;
use Tests\MockClasses\EventedJsonModel;
use Tests\MockClasses\Invokeable;

/**
 * Class JsonModelTest
 * @package Tests\Unit
 * @group JsonModel
 */
class JsonModelTest extends BaseTestCase
{
    /**
     * Define environment setup.
     *
     * @param  \Illuminate\Foundation\Application  $app
     * @return void
     */
    protected function defineEnvironment($app)
    {
        // Setup default database to use sqlite :memory:
        $app['config']->set('json-schema.base_url', 'https://schemas.dealerinspire.com/online-shopper/');
        $app['config']->set('json-schema.local_base_prefix', dirname(__FILE__) . '/../../tests/Schemas');
        $app['config']->set('json-schema.local_base_prefix_tests', dirname(__FILE__) . '/../../tests/Schemas');
        $app['config']->set('database.connections.testbench', [
            'driver'   => 'sqlite',
            'database' => ':memory:',
            'prefix'   => '',
        ]);
    }

    public function testAttributesCanBeTestedWithIsset(): void
    {
        $jsonModel = new ConcreteJsonModel();
        // Actually not set
        self::assertFalse(isset($jsonModel->foo));
        self::assertFalse(isset($jsonModel['foo']));
        self::assertFalse($jsonModel['foo'] ?? false);
        self::assertFalse($jsonModel->foo ?? false);

        $jsonModel->foo = 'bar'; // Set object style
        self::assertTrue(isset($jsonModel->foo));
        self::assertTrue(isset($jsonModel['foo']));
        self::assertSame('bar', $jsonModel['foo']);
        self::assertSame('bar', $jsonModel->foo);

        $jsonModel['fizz'] = 'buzz'; // Set array style
        self::assertTrue(isset($jsonModel->fizz));
        self::assertTrue(isset($jsonModel['fizz']));
        self::assertSame('buzz', $jsonModel['fizz']);
        self::assertSame('buzz', $jsonModel->fizz);
    }

    public function testObjectStyleAttributes(): void
    {
        $jsonModel = new ConcreteJsonModel();
        $jsonModel->something = 'yes'; //Set as object
        self::assertEquals($jsonModel->something, 'yes'); //Get as object
        self::assertEquals($jsonModel['something'], 'yes'); //Get as array
        self::assertEquals($jsonModel->toArray(), ['something' => 'yes']);
        self::assertEquals($jsonModel->toJSON(), '{"something":"yes"}');
    }

    public function testArrayStyleAttributes(): void
    {
        $jsonModel = new ConcreteJsonModel();
        $jsonModel['something'] = 'yes'; // Set as array
        self::assertEquals($jsonModel->something, 'yes'); //Get as object
        self::assertEquals($jsonModel['something'], 'yes'); //Get as array
        self::assertTrue(isset($jsonModel['something'])); // offsetExists
        self::assertEquals($jsonModel->toArray(), ['something' => 'yes']);
        self::assertEquals($jsonModel->toJSON(), '{"something":"yes"}');
    }

    public function testArrayStyleUnset(): void
    {
        $jsonModel = new ConcreteJsonModel(['something' => 'yes']);
        self::assertEquals('yes', $jsonModel['something']); // Is set
        self::assertTrue(isset($jsonModel['something'])); // offsetExists
        unset($jsonModel['something']); // Unset as array, uses offsetUnset
        self::assertNull($jsonModel['something']); // No longer set
        self::assertFalse(isset($jsonModel['something'])); // offsetExists
    }

    public function testAttributeStyleUnset(): void
    {
        $jsonModel = new ConcreteJsonModel(['something' => 'yes']);
        self::assertEquals('yes', $jsonModel->something); // Is set
        self::assertTrue(isset($jsonModel->something)); // offsetExists
        unset($jsonModel->something); // Unset as attribute, uses __unset which then uses offsetUnset
        self::assertNull($jsonModel->something); // No longer set
        self::assertFalse(isset($jsonModel->something)); // offsetExists
    }

    public function testArrayLiteralConstructor(): void
    {
        $data = ['string' => 'puppies', 'number' => 42, 'boolean' => true];
        $jsonModel = new ConcreteJsonModel($data);
        self::assertEquals($jsonModel->string, 'puppies');
        self::assertEquals($jsonModel->number, 42);
        self::assertEquals($jsonModel->boolean, true);
        self::assertEquals($jsonModel->toArray(), $data);
        self::assertEquals($jsonModel->toJSON(), json_encode($data));
        self::assertFalse($jsonModel->isLinked());
    }

    public function testCasts(): void
    {
        $caster = new class extends JsonModel {
            protected $casts = [
                'int' => 'int',
                'float' => 'float',
                'string' => 'string',
                'bool' => 'bool',
            ];
        };
        $caster->int = 123.4;
        self::assertSame(123, $caster->int);
        $caster->float = '123.4';
        self::assertSame(123.4, $caster->float);
        $caster->string = 0;
        self::assertSame('0', $caster->string);
        $caster->bool = 1;
        self::assertTrue($caster->bool);
    }

    public function testConstructorFailsWithNonModel(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("JsonModel couldn't understand the construct signature");
        new ConcreteJsonModel(new stdClass(), 'data');
    }

    /**
     * Build a model, initialized with the attribute 'data' set to the provided array,
     * and a JsonModel linked to that Model and whole attribute
     * @return array [Model, JsonModel]
     */
    protected function modelAndWholeAttributeJsonModel(): array
    {
        $model = mock(Model::class)->makePartial();
        $model->data = ['a' => 1];

        $jsonmodel = new class ($model, 'data') extends JsonModel {};
        return [$model, $jsonmodel];
    }

    /**
     * Given a model that has a property 'data' that contains JSON data,
     * Verify that we can instantiate a new JsonModel that loads that data.
     */
    public function testJsonModelLinks(): void
    {
        [$model, $jsonmodel] = $this->modelAndWholeAttributeJsonModel();
        self::assertSame($model->data, $jsonmodel->toArray());
        self::assertTrue($jsonmodel->isLinked());
    }

    // When changing a JsonModel attribute, JsonModel mutates, but the change is NOT immediately posted up to the model.
    public function testAttributeSetDoesntSaveToModel(): void
    {
        [$model, $jsonmodel] = $this->modelAndWholeAttributeJsonModel();
        $jsonmodel->a = 2;
        self::assertSame(2, $jsonmodel->a);
        self::assertSame(1, $model->data['a']);
    }

    // When you ->save() the change in the JsonModel, it uses ->update on the Model
    public function testAttributeSavesOnJsonModelSave(): void
    {
        [$model, $jsonmodel] = $this->modelAndWholeAttributeJsonModel();

        $jsonmodel->a = 2;
        self::assertSame(1, $model->data['a']);
        $model
            ->shouldReceive('save')
            ->with()
            ->once()
            ->andReturn(true);
        $saved = $jsonmodel->save();
        self::assertTrue($saved);
        self::assertSame(2, $jsonmodel->a);
        self::assertSame(2, $model->data['a']);
    }

    // If you use ->update on the JsonModel, it immediately uses ->update on the model
    public function testAttributeSavesOnJsonModelUpdate(): void
    {
        [$model, $jsonmodel] = $this->modelAndWholeAttributeJsonModel();
        $model
            ->shouldReceive('save')
            ->with()
            ->once()
            ->andReturn(true)
            ->andSet('data', ['a' => 2]);
        $updated = $jsonmodel->update(['a' => 2]);
        self::assertTrue($updated);
        self::assertSame(2, $jsonmodel->a);
        self::assertSame(2, $model->data['a']);
    }

    /**
     * Build a Model, initialized with the attribute 'data' set to an associative array 2 levels deep
     * and a JsonModel linked to that Model, attribute, and an array at the top depth
     * This will be the more common case in Online Shopper, a JsonModel on a part of deal data, like $deal->data['vehicle']
     * @return array [Model, JsonModel]
     */
    protected function modelAndPartialAttributeJsonModel(): array
    {
        $model = mock(Model::class)->makePartial();
        $model->data = [
            'vehicle' => ['vin' => 1],
            'untouched' => ['something' => 'else'],
        ];

        $jsonmodel = new class ($model, 'data', 'vehicle') extends JsonModel {};
        return [$model, $jsonmodel];
    }

    /**
     * Given a Model and a partial-attribute JsonModel
     * Verify that the new JsonModel loads that data.
     */
    public function testJsonModelPartialAttributeLinks(): void
    {
        [$model, $jsonmodel] = $this->modelAndPartialAttributeJsonModel();
        self::assertSame($model->data['vehicle'], $jsonmodel->toArray());
        self::assertTrue($jsonmodel->isLinked());
    }

    // When changing a JsonModel partial attribute, JsonModel mutates, but the change is NOT immediately posted up to the model.
    public function testPartialAttributeSetDoesntSaveToModel(): void
    {
        [$model, $jsonmodel] = $this->modelAndPartialAttributeJsonModel();
        $jsonmodel->vin = 2;
        self::assertSame(2, $jsonmodel->vin);
        self::assertSame(1, $model->data['vehicle']['vin']);
    }

    // When you ->save() the change in the JsonModel, it uses ->update on the Model
    public function testPartialAttributeSavesOnJsonModelSave(): void
    {
        [$model, $jsonmodel] = $this->modelAndPartialAttributeJsonModel();

        $jsonmodel->vin = 2;
        self::assertSame(1, $model->data['vehicle']['vin']);
        $model
            ->shouldReceive('save')
            ->once()
            ->andReturn(true);
        $saved = $jsonmodel->save();
        self::assertTrue($saved);
        self::assertSame(2, $jsonmodel->vin);
        self::assertSame(2, $model->data['vehicle']['vin']);
    }

    // When you ->update() the change in the JsonModel, it uses ->update on the Model immediately
    public function testPartialAttributeSavesOnJsonModelUpdate(): void
    {
        [$model, $jsonmodel] = $this->modelAndPartialAttributeJsonModel();

        $model
            ->shouldReceive('save')
            ->with()
            ->once()
            ->andReturn(true);
        $updated = $jsonmodel->update(['vin' => 2]);
        self::assertTrue($updated);
        self::assertSame(2, $jsonmodel->vin);
        self::assertSame(2, $model->data['vehicle']['vin']);
    }

    public function testUnlinkedJsonModelCantSave(): void
    {
        $unlinked = new ConcreteJsonModel();
        self::assertFalse($unlinked->isLinked());
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage("JsonModel isn't linked");
        $unlinked->save();
    }

    public function testUnlinkedJsonModelCantDelete(): void
    {
        $unlinked = new ConcreteJsonModel();
        self::assertFalse($unlinked->isLinked());
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage("JsonModel isn't linked");
        $unlinked->delete();
    }

    protected function mockLinkedValidatedJsonModel(): array
    {
        $model = mock(Model::class)->makePartial();
        $model->data = ['a' => 1];

        $jsonmodel = new class ($model, 'data') extends JsonModel {
            public const SCHEMA = '{ "properties":{ "a":{"type":"integer"} }, "required_params":["a"]}';
        };
        return [$model, $jsonmodel];
    }

    protected function mockLinkedValidatedJsonModelWithChildrenModel(): array
    {
        $model = mock(Model::class)->makePartial();
        $model->data = ['a' => ['aa' => 11, 'bb' => 22], 'b' => 2];

        $jsonmodel = new class ($model, 'data') extends JsonModel {
            use HasJsonModelAttributes;

            /** @var array  */
            protected $jsonModelAttributes = [
                'a' => [ConcreteJsonModel::class, 'a'],
            ];

            public const SCHEMA = '{"type":"object"}';
        };
        return [$model, $jsonmodel];
    }

    public function testRecursiveUpdateDoesNotCompletelyReplaceData(): void
    {
        /** @var $jsonmodel JsonModel */
        [$model, $jsonmodel] = $this->mockLinkedValidatedJsonModelWithChildrenModel();

        $model
            ->shouldReceive('save')
            ->once()
            ->with()
            ->andReturn(true);

        $jsonmodel->updateRecursive(['a' => ['aa' => 33]]);
        self::assertCanonicallySame(
            ['a' => ['aa' => 33, 'bb' => 22], 'b' => 2],
            $model->data
        );
    }

    public function testRecursiveUpdateSavesEachChildGroupOnce(): void
    {
        /** @var $jsonmodel JsonModel */
        [$model, $jsonmodel] = $this->mockLinkedValidatedJsonModelWithChildrenModel();

        $model
            ->shouldReceive('save')
            ->once()
            ->with()
            ->andReturn(true);

        $jsonmodel->updateRecursive([
            'a' => [
                'aa' => 'updated on child',
                'cc' => 'new on child',
            ],
            'b' => 'updated on parent',
            'c' => 'new on parent',
        ]);
        self::assertEquals('updated on child', $model->data['a']['aa']);
        self::assertEquals('new on child', $model->data['a']['cc']);
        self::assertEquals('updated on parent', $model->data['b']);
        self::assertEquals('new on parent', $model->data['c']);
    }

    public function testLinkedJsonModelValidatesBeforeSave(): void
    {
        [$model, $jsonmodel] = $this->mockLinkedValidatedJsonModel();
        SchemaValidator::shouldReceive('validateOrThrow')
            ->once()
            ->with(
                $jsonmodel,
                $jsonmodel::SCHEMA,
                'Anonymous Descendent of Json Model contains invalid data!',
                false,
                400,
            )
            ->andReturn(true);

        $model
            ->shouldReceive('save')
            ->once()
            ->with()
            ->andReturn(true);

        $save_status = $jsonmodel->update(['a' => 2]);
        self::assertTrue($save_status);
    }

    public function testValidateWithUriSchema(): void
    {
        $jsonModel = new class (['vin' => '11111111111111111', 'make' => 'DeLorean', 'model' => 'DMC-12']) extends JsonModel {
            public const SCHEMA = 'https://schemas.dealerinspire.com/online-shopper/vehicle.json';
        };
        self::assertTrue($jsonModel->validateOrThrow());
    }

    public function testLinkedJsonModelValidationHaltsSave(): void
    {
        [$model, $jsonmodel] = $this->mockLinkedValidatedJsonModel();
        $model
            ->shouldReceive('update')
            ->never()
            ->andReturn(false); // If validation passes due to a future error, this gives you a clearer "should have never updated" error instead of "save returned null"
        $this->expectException(JsonSchemaValidationException::class);
        $this->expectExceptionMessage('Anonymous Descendent of Json Model contains invalid data!');

        $jsonmodel->a = 'not_an_integer';

        $jsonmodel->save();
    }

    /**
     * This test verifies that if we register a saving observer that returns literally false
     * the Save is aborted without ever reaching the Model
     */
    public function testSavingObserverPreventsSave(): void
    {
        /** @var Model $model */
        $model = mock(Model::class)->makePartial();
        $jsonModel = new EventedJsonModel($model, 'data');
        $jsonModel->saving_returns = false;
        // Saves should be rejected by the observer and never reach the model
        $model
            ->shouldReceive('save')
            ->never()
            ->andReturn(false);
        $status = $jsonModel->save();
        self::assertFalse($status);
    }

    /**
     * Creating observer is called.
     * It's called before saving.
     * Then the save works, too
     */
    public function testCreatingObserverOnNewJsonModelIsInvoked(): void
    {
        $model = mock(Model::class)->makePartial();
        // extend EventedJsonModel to explicitly test that creating was called before saving
        $jsonModel = new class extends EventedJsonModel {
            public static function boot(): void
            {
                static::saving(static function ($noob) {
                    if (!$noob->exists && !$noob->creating_fired) {
                        throw new Exception('Saving before creating');
                    }
                });
                parent::boot();
            }
        };

        // Saves should succeed
        $model
            ->shouldReceive('save')
            ->once()
            ->andReturn(true);
        self::assertSame(0, $jsonModel->creating_fired);
        self::assertSame(0, $jsonModel->saving_fired);
        $jsonModel->link($model, 'data');
        $status = $jsonModel->save();
        self::assertTrue($status);
        self::assertSame(1, $jsonModel->creating_fired);
        self::assertSame(1, $jsonModel->saving_fired);
    }

    public function testCreatingObserverFailsHaltsSave(): void
    {
        $model = mock(Model::class)->makePartial();
        $jsonModel = new EventedJsonModel();
        $jsonModel->creating_returns = false;
        // Saves should be rejected by the observer and never reach the model
        $model
            ->shouldReceive('save')
            ->never()
            ->andReturn(false);
        $jsonModel->link($model, 'data');
        $status = $jsonModel->save();
        self::assertFalse($status);
    }

    public function testCreatingObserverSkippedForExistingJsonModel(): void
    {
        $model = mock(Model::class)->makePartial();
        $model->data = ['totally' => 'here'];
        $jsonModel = new EventedJsonModel($model, 'data');
        self::assertTrue($jsonModel->exists);
        $model
            ->shouldReceive('save')
            ->once()
            ->andReturn(true);
        $jsonModel->link($model, 'data');
        self::assertSame(0, $jsonModel->creating_fired);
        self::assertSame(0, $jsonModel->created_fired);
        self::assertSame(0, $jsonModel->saving_fired);
        self::assertSame(0, $jsonModel->saved_fired);
        $status = $jsonModel->save();
        self::assertTrue($status);
        self::assertSame(0, $jsonModel->creating_fired);
        self::assertSame(0, $jsonModel->created_fired);
        self::assertSame(1, $jsonModel->saving_fired);
        self::assertSame(1, $jsonModel->saved_fired);
    }

    public function testCreatedAndCreatingFireOnlyOnce(): void
    {
        $model = mock(Model::class)->makePartial();
        // Note, passing $upstream model and $upstream_attribute in the constructor
        $jsonModel = new EventedJsonModel();
        self::assertFalse($jsonModel->exists);
        $model
            ->shouldReceive('save')
            ->twice()
            ->andReturn(true);
        $jsonModel->link($model, 'data');
        self::assertSame(0, $jsonModel->creating_fired);
        self::assertSame(0, $jsonModel->created_fired);
        self::assertSame(0, $jsonModel->saving_fired);

        $jsonModel->save();
        self::assertTrue($jsonModel->exists);
        self::assertSame(1, $jsonModel->creating_fired);
        self::assertSame(1, $jsonModel->created_fired);
        self::assertSame(1, $jsonModel->saving_fired);

        // Save again, created&creating counters don't increment, saving does
        $jsonModel->save();
        self::assertSame(1, $jsonModel->creating_fired);
        self::assertSame(1, $jsonModel->created_fired);
        self::assertSame(2, $jsonModel->saving_fired);
    }

    /**
     * This test verifies that if we register a deleting observer that returns literally false
     * the Delete is aborted without ever reaching the Model
     */
    public function testDeletingObserverPreventsDelete(): void
    {
        $model = mock(Model::class)->makePartial();
        $jsonModel = new EventedJsonModel($model, 'data');
        $jsonModel->deleting_returns = false; // Reject
        // Saves should be rejected by the observer and never reach the model
        $model
            ->shouldReceive('save')
            ->never()
            ->andReturn(false);
        $status = $jsonModel->delete();
        self::assertFalse($status);
    }

    public function testImplementsJsonSerializableContract(): void
    {
        $jsonModel = new ConcreteJsonModel(['a' => 1]);
        self::assertSame(json_encode($jsonModel), '{"a":1}');
    }

    public function testRecursionThrowsJsonEncodingException(): void
    {
        $jsonModel = new ConcreteJsonModel(['a' => 1]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Cannot set a recursive property.');

        $jsonModel->b = $jsonModel;
    }



    public function testCantGetLinkedDataWhileNotLinked(): void
    {
        $jsonModel = new ConcreteJsonModel(['local' => 'data']);
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage("JsonModel isn't linked");
        $jsonModel->getLinkedData();
    }

    public function testCantSetLinkedDataWhileNotLinked(): void
    {
        $jsonModel = new ConcreteJsonModel(['local' => 'data']);
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage("JsonModel isn't linked");
        $jsonModel->setLinkedData();
    }

    public function testNewCollection(): void
    {
        $model = new ConcreteJsonModel();
        $collection = $model->newCollection();

        self::assertInstanceOf(CollectionOfJsonModels::class, $collection);
        self::assertEmpty($collection);
        self::assertSame(
            ConcreteJsonModel::class,
            getProperty($collection, 'itemClass'),
            "New collection's type is set to the creating models exact type",
        );
    }

    public function testEmptyModelToJsonReturnsEmptyObject(): void
    {
        $model = new ConcreteJsonModel();

        self::assertSame('{}', $model->toJson());
    }

    public function testIsEmptyReturnsTrueForEmptyObject(): void
    {
        $model = new ConcreteJsonModel();

        self::assertTrue($model->isEmpty());
    }

    public function testIsLinkedToInstanceOfIsLinkedAndChildOf(): void
    {
        $model = new class extends ConcreteJsonModel {};
        $model->link(new ConcreteJsonModel(), 'test');

        self::assertTrue($model->isLinkedToInstanceOf(ConcreteJsonModel::class));
    }

    public function testIsLinkedToInstanceOfIsNotLinkedButChildOf(): void
    {
        $model = new class extends ConcreteJsonModel {
            public function __construct()
            {
                $this->upstream_model = new ConcreteJsonModel();
            }
        };

        self::assertFalse($model->isLinkedToInstanceOf(ConcreteJsonModel::class));
    }

    public function testIsLinkedToInstanceOfIsLinkedButNotChildOf(): void
    {
        $model = new class extends ConcreteJsonModel {
            public function __construct()
            {
                $this->upstream_model = new UpstreamModel();
                $this->upstream_attribute = 'test';
            }
        };

        self::assertFalse($model->isLinkedToInstanceOf(ConcreteJsonModel::class));
    }

    public function testGetAncestorOfTypeIsNullOnNonLinkedModel(): void
    {
        $model = new ConcreteJsonModel();

        $result = $model->getAncestorOfType(UpstreamModel::class);
        self::assertNull($result);
    }

    public function testGetAncestorOfTypeFindsParentModel(): void
    {
        $upstream = new UpstreamModel();
        $result = $upstream->child->getAncestorOfType(UpstreamModel::class);
        self::assertSame($upstream, $result);
    }

    public function testGetAncestorOfTypeFindsParentJsonModel(): void
    {
        $upstream = new UpstreamModel();
        $result = $upstream->child->grandchild->getAncestorOfType(DownstreamModel::class);
        self::assertSame($upstream->child, $result);
    }

    public function testGetAncestorOfTypeFindsClassRecursive(): void
    {
        $upstream = new UpstreamModel();
        $result = $upstream->child->grandchild->getAncestorOfType(UpstreamModel::class);
        self::assertSame($upstream, $result);
    }

    public function testHydratesChildrenAtCreate()
    {
        $upstream = new UpstreamModel();
        $attributeCache = getProperty($upstream, 'jsonModelAttributeCache');
        self::assertArrayHasKey('child', $attributeCache);
        self::assertInstanceOf(DownstreamModel::class, $attributeCache['child']);
    }

    public function testDoesNotHydrateChildrenEquivalentToNull()
    {
        $downstream = new DownstreamModel();
        $attributeCache = getProperty($downstream, 'jsonModelAttributeCache');
        self::assertArrayNotHasKey('anotherone', $attributeCache);

        $tradeinWithAnotherone = new DownstreamModel(['anotherone' => ['foo' => 100000]]);
        $attributeCache = getProperty($tradeinWithAnotherone, 'jsonModelAttributeCache');
        self::assertArrayHasKey('anotherone', $attributeCache);
        self::assertInstanceOf(NullableModel::class, $attributeCache['anotherone']);
    }

    public function testCascadeEvents()
    {
        $model = mock(Model::class)->makePartial();
        $model
            ->shouldReceive('save')
            ->once()
            ->andReturn(true);
        $parent = new class ($model, 'data') extends EventedJsonModel {
            use HasJsonModelAttributes;
            protected $jsonModelAttributes = [
                'child' => [EventedJsonModel::class, 'child'],
            ];
        };

        self::assertInstanceOf(EventedJsonModel::class, $parent->child);

        self::assertSame(0, $parent->saving_fired);
        self::assertSame(0, $parent->saved_fired);
        self::assertSame(0, $parent->child->saving_fired);
        self::assertSame(0, $parent->child->saved_fired);
        self::assertSame(0, $parent->child->creating_fired);
        self::assertSame(0, $parent->child->created_fired);

        $parent->child->pizza = 'pepperoni';
        $saveStatus = $parent->save(); // Saving on the *parent* cascades events down to children
        self::assertTrue($saveStatus);
        self::assertSame(1, $parent->saving_fired);
        self::assertSame(1, $parent->saved_fired);
        self::assertSame(1, $parent->child->saving_fired);
        self::assertSame(1, $parent->child->saved_fired);
        self::assertSame(1, $parent->child->creating_fired);
        self::assertSame(1, $parent->child->created_fired);
    }

    public function testCascadePreventsEvents()
    {
        $model = mock(Model::class)->makePartial();
        $model
            ->shouldReceive('save')
            ->never() // Saving will be prevented by the child
            ->andReturn(true);
        $parent = new class ($model, 'data') extends EventedJsonModel {
            use HasJsonModelAttributes;
            protected $jsonModelAttributes = [
                'child' => [EventedJsonModel::class, 'child'],
            ];
        };

        $parent->child->saving_returns = false;

        $parent->child->pizza = 'pepperoni';
        $saveStatus = $parent->save(); // Saving on the *parent* cascades events down to children
        self::assertFalse($saveStatus);
        self::assertSame(1, $parent->saving_fired, 'Parent saving observer should run before child');
        self::assertSame(0, $parent->saved_fired);
        self::assertSame(
            1,
            $parent->child->creating_fired,
            'Creating should be fired first, it is allowed, continues to saving',
        );
        self::assertSame(1, $parent->child->saving_fired, 'Saving should be called, it returns false');
        self::assertSame(0, $parent->child->saved_fired, 'Saved should not be called');
        self::assertSame(0, $parent->child->created_fired, 'Created should not be called');
    }

    public function testCastToDatetime(): void
    {
        $model = new class extends JsonModel {
            protected $casts = [
                'field' => 'datetime',
            ];
        };

        // Set to carbon, internal rep is a reasonable string, and can retrieve as Carbon
        $model->field = Carbon::parse('2021-01-01 01:01:01');
        // So weirdly, it uses a slightly different internal representation than what it encodes on wire and to disk.
        // It's still the same logical time, and it still conforms to ISO-8601.
        self::assertSame('2021-01-01T01:01:01+00:00', getProperty($model, 'attributes')['field']);
        self::assertInstanceOf(Carbon::class, $model->field);
        $modelString = $model->toJson();
        self::assertStringContainsString('"field":"2021-01-01T01:01:01.000000Z"', $modelString);

        // Set with any reasonable string, internal rep is a more precise string, and can retrieve as Carbon
        $model->field = '2022-02-02 02:02:02';
        self::assertSame('2022-02-02T02:02:02+00:00', getProperty($model, 'attributes')['field']);
        self::assertInstanceOf(Carbon::class, $model->field);
        self::assertSame('2022-02-02T02:02:02.000000Z', $model->field->toJSON());
    }

    public function testSafeUpdate(): void
    {
        $mixedChanges = [
            'email' => 'kaboom',
            'mobile_phone' => 'dontcall',
            'first_name' => 'Jeremy',
        ];

        $model = mock(Model::class)->makePartial();
        $jsonModel = new class ($model, 'data') extends EventedJsonModel {
            const SCHEMA = "person.json";
        };
        $jsonModel->mobile_phone = '8885551111';

        $model
            ->shouldReceive('save')
            ->once()
            ->andReturn(true);

        $jsonModel->safeUpdate($mixedChanges);
        self::assertFalse(isset($jsonModel->email)); // attribute is invalid, revert to unset
        self::assertSame('8885551111', $jsonModel->mobile_phone); // attribute is invalid, revert to previous value
        self::assertSame('Jeremy', $jsonModel->first_name);
    }
}
/**
 * These are classes and models needed to test inheritance, etc.
 */
class UpstreamModel extends JsonModel
{
    use HasJsonModelAttributes;

    /** @var array Json Model Attributes */
    protected $jsonModelAttributes = [
        'child' => [DownstreamModel::class, 'child'],
    ];
}

class DownstreamModel extends JsonModel
{
    use HasJsonModelAttributes;

    /** @var array Json Model Attributes */
    protected $jsonModelAttributes = [
        'grandchild' => [BeyondDownstreamModel::class, 'grandchild'],
        'anotherone' => [NullableModel::class, 'anotherone'],
    ];
}

class BeyondDownstreamModel extends JsonModel
{
}

class NullableModel extends JsonModel
{
    use NullWhenUsedAsAttributeWhenEmpty;
}
