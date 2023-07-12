<?php
/**
 * Test the trait HasJsonModelAttributes and how it interoperates with Models
 */

declare(strict_types=1);

namespace Tests\Unit\Traits;

use Carsdotcom\LaravelJsonModel\CollectionOfJsonModels;
use Carsdotcom\LaravelJsonModel\Exceptions\UniqueException;
use Carsdotcom\LaravelJsonModel\Traits\HasJsonModelAttributes;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;
use Tests\BaseTestCase;
use Tests\MockClasses\ConcreteJsonModel;
use Tests\MockClasses\ConcreteNullableJsonModel;

/**
 * Class HasJsonModelAttributes
 * @package Tests\Unit\Traits
 * @group JsonModel
 */
class HasJsonModelAttributesTest extends BaseTestCase
{
    public function testWholeAttributeExists()
    {
        $model = new class(['raw' => ['a' => 1, 'b' => true]]) extends Model {
            use HasJsonModelAttributes;
            protected $guarded = [];
            protected $casts = ['raw' => 'json'];
            protected $jsonModelAttributes = ['jsonModel' => [ConcreteJsonModel::class, 'raw']];
        };
        self::assertInstanceOf(ConcreteJsonModel::class, $model->jsonModel);
        self::assertSame(1, $model->jsonModel->a);
    }

    public function testWholeAttributeMissingIsEmptyObject()
    {
        $model = new class() extends Model {
            use HasJsonModelAttributes;
            protected $guarded = [];
            protected $casts = ['raw' => 'json'];
            protected $jsonModelAttributes = ['jsonModel' => [ConcreteJsonModel::class, 'raw']];
        };
        // Missing is empty object
        self::assertInstanceOf(ConcreteJsonModel::class, $model->jsonModel);
        self::assertTrue($model->jsonModel->isEmpty());

        $model->fill(['raw'=>null]);

        // Null is empty object
        self::assertInstanceOf(ConcreteJsonModel::class, $model->jsonModel);
        self::assertTrue($model->jsonModel->isEmpty());
    }

    public function testWholeAttributeMissingIsNull()
    {
        $model = new class() extends Model {
            use HasJsonModelAttributes;
            protected $guarded = [];
            protected $casts = ['raw' => 'json'];
            protected $jsonModelAttributes = ['jsonModel' => [ConcreteNullableJsonModel::class, 'raw']];
        };
        // Missing is null
        self::assertNull($model->jsonModel);

        $model->fill(['raw'=>null]);

        // Null is null
        self::assertNull($model->jsonModel);
    }

    public function testPartialAttributeExists()
    {
        $model = new class(['container'=>['part'=>['a'=>1]]]) extends Model {
            use HasJsonModelAttributes;
            protected $guarded = [];
            protected $casts = ['container' => 'json'];
            protected $jsonModelAttributes = ['jsonModel' => [ConcreteJsonModel::class, 'container', 'part']];
        };
        self::assertInstanceOf(ConcreteJsonModel::class, $model->jsonModel);
        self::assertSame(1, $model->jsonModel->a);
    }

    public function testPartialAttributeMissingIsNull()
    {
        $model = new class() extends Model {
            use HasJsonModelAttributes;
            protected $guarded = [];
            protected $casts = ['container' => 'json'];
            protected $jsonModelAttributes = ['jsonModel' => [ConcreteNullableJsonModel::class, 'container', 'part']];
        };
        // Container missing is null
        self::assertNull($model->jsonModel);

        // Container present, partial missing is null
        $model->fill(['container'=>[]]);
        self::assertNull($model->jsonModel);

        // Container present, partial null is null
        $model->fill(['container'=>['part'=>null]]);
        self::assertNull($model->jsonModel);

        // Container present, partial empty is null
        $model->fill(['container'=>['part'=>[]]]);
        self::assertNull($model->jsonModel);
    }

    public function testPartialAttributeMissingIsEmptyObject()
    {
        $model = new class() extends Model {
            use HasJsonModelAttributes;
            protected $guarded = [];
            protected $casts = ['container' => 'json'];
            protected $jsonModelAttributes = ['jsonModel' => [ConcreteJsonModel::class, 'container', 'part']];
        };
        // Container missing is empty object
        self::assertInstanceOf(ConcreteJsonModel::class, $model->jsonModel);
        self::assertTrue($model->jsonModel->isEmpty());

        // Container present, partial missing is empty object
        $model->fill(['container'=>[]]);
        self::assertInstanceOf(ConcreteJsonModel::class, $model->jsonModel);
        self::assertTrue($model->jsonModel->isEmpty());

        // Container present, partial null is empty object
        $model->fill(['container'=>['part'=>null]]);
        self::assertInstanceOf(ConcreteJsonModel::class, $model->jsonModel);
        self::assertTrue($model->jsonModel->isEmpty());

        // Container present, partial empty is empty object
        $model->fill(['container'=>['part'=>[]]]);
        self::assertInstanceOf(ConcreteJsonModel::class, $model->jsonModel);
        self::assertTrue($model->jsonModel->isEmpty());
    }

    public function testJsonModelAttributesConfigMissing()
    {
        $model = new class() extends Model {
            use HasJsonModelAttributes;
            protected $guarded = [];
            protected $casts = ['raw'=>'json'];
        };
        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage(get_class($model) . " must define a property jsonModelAttributes to use HasJsonModelAttributes");
        $model->jsonModel; // any magic getter or setter will trigger isJsonModelAttribute
    }

    public function testJsonModelAttributesConfigNotArray()
    {
        $model = new class() extends Model {
            use HasJsonModelAttributes;
            protected $guarded = [];
            protected $casts = ['raw'=>'json'];
            protected $jsonModelAttributes = 42;
        };
        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage(get_class($model) . " must define an array for property jsonModelAttributes");
        $model->jsonModel; // any magic getter or setter will trigger isJsonModelAttribute
    }

    public function testJsonModelAttributesConfigTooShort()
    {
        $model = new class() extends Model {
            use HasJsonModelAttributes;
            protected $guarded = [];
            protected $casts = ['raw'=>'json'];
            protected $jsonModelAttributes = ['jsonModel' => [ConcreteJsonModel::class]];
        };
        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage("Unusable jsonModelAttributes in " . get_class($model));
        $model->jsonModel; // any magic getter or setter will trigger isJsonModelAttribute
    }

    public function testJsonModelAttributesConfigTooLong()
    {
        $model = new class() extends Model {
            use HasJsonModelAttributes;
            protected $guarded = [];
            protected $casts = ['raw'=>'json'];
            protected $jsonModelAttributes = [
                'jsonModel' => [
                    ConcreteJsonModel::class,
                    'raw',
                    'key',
                    CollectionOfJsonModels::NOT_A,
                    'primary_key',
                    'nonsense'
                ]];
        };
        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage("Unusable jsonModelAttributes in " . get_class($model));
        $model->jsonModel; // any magic getter or setter will trigger isJsonModelAttribute
    }

    public function testJsonModelAttributesConfigEntryNotArray()
    {
        $model = new class() extends Model {
            use HasJsonModelAttributes;
            protected $guarded = [];
            protected $casts = ['raw' => 'json'];
            protected $jsonModelAttributes = ['jsonModel' => ConcreteJsonModel::class];
        };
        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage("Unusable jsonModelAttributes in " . get_class($model));
        $model->jsonModel; // any magic getter or setter will trigger isJsonModelAttribute
    }

    protected function modelWithWholeAttributeJsonModel()
    {
        return new class(['raw' => ['a' => 1, 'b' => true], 'nonJson' => 'Freddy' ]) extends Model {
            use HasJsonModelAttributes;

            /** @var array $guarded let me ->fill() any attribute */
            protected $guarded = [];

            /** @var array $casts treat container as a JSON column */
            protected $casts = ['raw' => 'json'];

            /** @var array $jsonModelAttributes config for the HasJsonModelAttributes trait */
            protected $jsonModelAttributes = ['jsonModel' => [ConcreteJsonModel::class, 'raw']];
        };
    }

    public function testMagicGetDoesntInterfereWithNonJsonAttributes()
    {
        $model = $this->modelWithWholeAttributeJsonModel();
        self::assertSame('Freddy', $model->nonJson);
        self::assertNull($model->completelyMissing);
    }

    public function testMagicSetDoesntInterfereWithNonJsonAttributes()
    {
        $model = $this->modelWithWholeAttributeJsonModel();
        $model->nonJson = 'Chucky';
        self::assertSame('Chucky', $model->nonJson);
        $model->completelyMissing = 'First';
        self::assertSame('First', $model->completelyMissing);
    }


    public function testSetWholeAttributeFromArray()
    {
        $model = $this->modelWithWholeAttributeJsonModel();
        $model->jsonModel = ['c'=>'3P0'];
        self::assertSame(['c'=>'3P0'], $model->raw); // Overwriting everything on raw, a and b are lost
    }

    public function testSetWholeAttributeFromObject()
    {
        $model = $this->modelWithWholeAttributeJsonModel();
        $model->jsonModel = new ConcreteJsonModel(['c'=>'3P0']);
        self::assertSame(['c'=>'3P0'], $model->raw); // Overwriting everything on raw, a and b are lost
    }

    public function testSetWholeAttributeNull()
    {
        $model = $this->modelWithWholeAttributeJsonModel();
        $this->expectExceptionObject(new InvalidArgumentException("jsonModel must be a Tests\MockClasses\ConcreteJsonModel or valid array"));
        $model->jsonModel = null;
    }

    public function testDeleteWholeAttribute(): void
    {
        $model = $this->modelWithWholeAttributeJsonModel();
        $model->jsonModel = ['first_name' => 'Zap', 'last_name' => 'Brannigan'];
        self::assertArrayHasKey('raw', getProperty($model, 'attributes'));
        self::assertStringContainsString('"jsonModel":', $model->toJson());

        unset($model->jsonModel);

        self::assertArrayNotHasKey('raw', getProperty($model, 'attributes'));
        self::assertStringNotContainsString('"jsonModel":', $model->toJson());
    }

    public function testSetWholeAttributeBadType()
    {
        $model = $this->modelWithWholeAttributeJsonModel();
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("jsonModel must be a Tests\MockClasses\ConcreteJsonModel or valid array");
        $model->jsonModel = (object)['c'=>'3P0'];
    }

    protected function modelWithPartialAttributeJsonModel()
    {
        return new class(['container' => ['part' => ['a' => 1]]]) extends Model {
            use HasJsonModelAttributes;

            /** @var array $guarded let me ->fill() any attribute */
            protected $guarded = [];

            /** @var array $casts treat container as a JSON column */
            protected $casts = ['container' => 'json'];

            /** @var array $jsonModelAttributes config for the HasJsonModelAttributes trait */
            protected $jsonModelAttributes = ['jsonModel' => [ConcreteJsonModel::class, 'container', 'part']];
        };
    }

    public function testSetPartialAttributeFromArray()
    {
        $model = $this->modelWithPartialAttributeJsonModel();
        $model->jsonModel = ['c'=>'3P0'];
        self::assertSame(['c'=>'3P0'], $model->container['part']);
    }

    public function testSetPartialAttributeFromObject()
    {
        $model = $this->modelWithPartialAttributeJsonModel();
        $model->jsonModel = new ConcreteJsonModel(['c'=>'3P0']);
        self::assertSame(['c'=>'3P0'], $model->container['part']);
    }

    public function testSetPartialAttributeNull()
    {
        $model = $this->modelWithPartialAttributeJsonModel();

        $this->expectExceptionObject(new InvalidArgumentException("jsonModel must be a Tests\MockClasses\ConcreteJsonModel or valid array"));

        $model->jsonModel = null;
    }

    public function testDeletePartialAttribute(): void
    {
        $model = $this->modelWithPartialAttributeJsonModel();
        $model->jsonModel = ['first_name' => 'Zap', 'last_name' => 'Brannigan'];
        self::assertArrayHasKey('container', getProperty($model, 'attributes'));
        self::assertArrayHasKey('part', $model->container);
        self::assertStringContainsString('"jsonModel":', $model->toJson());

        unset($model->jsonModel);

        self::assertArrayHasKey('container', getProperty($model, 'attributes'));
        self::assertArrayNotHasKey('part', $model->container);
        self::assertStringNotContainsString('"jsonModel":', $model->toJson());
    }

    public function testSetPartialAttributeBadType()
    {
        $model = $this->modelWithPartialAttributeJsonModel();
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("jsonModel must be a Tests\MockClasses\ConcreteJsonModel or valid array");
        $model->jsonModel = (object)['c'=>'3P0'];
    }

    public function testJsonModelCollectionAsAttribute()
    {
        $model = new class() extends Model {
            use HasJsonModelAttributes;
            protected $guarded = [];
            protected $casts = ['raw'=>'json'];
            protected $jsonModelAttributes = [
                'jsons' => [
                    ConcreteJsonModel::class,
                    'raw',
                    null,
                    CollectionOfJsonModels::IS_A,
                ]
            ];
        };
        self::assertInstanceOf(CollectionOfJsonModels::class, $model->jsons);
        self::assertCount(0, $model->jsons);
    }

    public function testJsonModelCollectionAsAttributeHonorsPrimaryKey()
    {
        $model = new class() extends Model {
            use HasJsonModelAttributes;
            protected $guarded = [];
            protected $casts = ['raw'=>'json'];
            protected $jsonModelAttributes = [
                'jsons' => [
                    ConcreteJsonModel::class,
                    'raw',
                    null,
                    CollectionOfJsonModels::IS_A,
                    'id'
                ]
            ];
        };
        self::assertInstanceOf(CollectionOfJsonModels::class, $model->jsons);
        $model->jsons->push(['id'=>1, 'data'=>'first']);
        $this->expectException(UniqueException::class);
        $this->expectExceptionMessage("Collection can't contain duplicate id 1");
        $model->jsons->push(['id'=>1, 'data'=>'second']);
    }

    public function testJsonModelCollectionHydratesChildrenToClass()
    {
        $model = new class(['raw' => [['id'=>1]]]) extends Model {
            use HasJsonModelAttributes;
            protected $guarded = [];
            protected $casts = ['raw'=>'json'];
            protected $jsonModelAttributes = [
                'jsons' => [
                    ConcreteJsonModel::class,
                    'raw',
                    null,
                    CollectionOfJsonModels::IS_A,
                    'id'
                ]
            ];
        };
        self::assertInstanceOf(CollectionOfJsonModels::class, $model->jsons);
        self::assertCount(1, $model->jsons);
        self::assertInstanceOf(ConcreteJsonModel::class, $model->jsons->first());
        self::assertSame(1, $model->jsons->first()->id);
    }

    public function testAttributeCanBeTestedWithIsset()
    {
        $model = $this->modelWithWholeAttributeJsonModel();
        // Remember this is pre-populated in the method
        self::assertSame(1, $model->jsonModel->a);
        self::assertSame(1, $model->jsonModel->a ?? false);
        self::assertFalse($model->jsonModel->other ?? false);
        self::assertTrue(isset($model->jsonModel));

        unset($model->jsonModel);
        self::assertFalse(isset($model->jsonModel));
        self::assertFalse($model->jsonModel->a ?? false);
        self::assertFalse($model->jsonModel->other ?? false);
    }
}
