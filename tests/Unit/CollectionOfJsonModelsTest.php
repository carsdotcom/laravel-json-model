<?php
/**
 * Test the CollectionOfJsonModels feature
 */
declare(strict_types=1);

namespace Tests\Unit;

use Carsdotcom\LaravelJsonModel\CollectionOfJsonModels;
use Carsdotcom\JsonSchemaValidation\Exceptions\JsonSchemaValidationException;
use Carsdotcom\LaravelJsonModel\Exceptions\UniqueException;
use Carsdotcom\LaravelJsonModel\JsonModel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Tests\BaseTestCase;
use Tests\MockClasses\EventedJsonModel;
use Tests\MockClasses\EventedModelWithJsonAttributes;
use Tests\Mocks\Models\Vehicle;

/**
 * Class CollectionOfJsonModelsTest
 * @package Tests\Unit
 * @group JsonModel
 */
class CollectionOfJsonModelsTest extends BaseTestCase
{
    public function testTypeOfLoadedItems()
    {
        $collection = new CollectionOfJsonModels();
        $collection->setType(Vehicle::class);
        $model = new class extends Model {};
        //Fill a model with an array of vehicle-shaped data (as assoc array)
        $model->vehicles = [
           ['vin' => '11111111111111111']
        ];
        // Link it up
        $collection->link($model, 'vehicles')->fresh();
        self::assertIsArray($model->vehicles[0]);
        self::assertInstanceOf(Vehicle::class, $collection[0]);
    }

    public function testCantFreshWithoutSetType()
    {
        $collection = new CollectionOfJsonModels();
        $model = new class extends Model {};
        //Fill a model with an array of vehicle-shaped data (as assoc array)
        $model->vehicles = [
            Vehicle::factory()
                ->make()
                ->mugglify(),
        ];
        // Link it up
        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage("Can't load CollectionOfJsonModels until type has been set.");
        $collection->link($model, 'vehicles')->fresh();
    }

    /**
     * Build a model, initialized with the attribute 'data' set to the provided array,
     * and a JsonModel linked to that Model and whole attribute
     * @return array [Model, JsonModel]
     */
    protected function modelAndCollectionOfVehicles(): array
    {
        $model = mock(Model::class)->makePartial();
        $model->vehicles = [];

        $collection = new CollectionOfJsonModels();
        $collection->setType(Vehicle::class);
        $collection->link($model, 'vehicles');
        return [$model, $collection];
    }

    public function testSaveSetsAttributesAndCascadesSave()
    {
        [$model, $collection] = $this->modelAndCollectionOfVehicles();
        $model
            ->shouldReceive('save')
            ->with()
            ->once()
            ->andReturn(true);
        $vehicle = Vehicle::factory()->make();
        $collection->push($vehicle)->save();
        self::assertNotNull($model->vehicles);
        self::assertCanonicallySame($model->vehicles[0], $vehicle);
    }

    public function testCollectionChangesWithoutSaveDontSaveModel()
    {
        [$model, $collection] = $this->modelAndCollectionOfVehicles();
        $model
            ->shouldReceive('save')
            ->never()
            ->andReturn(true);
        $vehicle = Vehicle::factory()->make();
        $collection->push($vehicle); // Push, no ->save()
    }

    public function testCantValidateWithoutItemSchema()
    {
        $collection = new CollectionOfJsonModels();
        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage("Can't validate a CollectionOfJsonModels until type has been set.");
        $collection->validateOrThrow();
    }

    public function testEmptyCollectionIsValid()
    {
        $collection = new CollectionOfJsonModels();
        $collection->setType(Vehicle::class);
        self::assertCount(0, $collection);
        self::assertTrue($collection->validateOrThrow());
    }

    public function testValidationIdentifiesProblemElementAndIsRecursive()
    {
        $collection = new CollectionOfJsonModels();
        $collection->setType(Vehicle::class);

        // Healthy zero
        $collection->push(Vehicle::factory()->make());

        // Unhealthy one
        $collection->push(Vehicle::factory()->make());
        $collection[1]->vin = 'notavin';

        try {
            $collection->validateOrThrow();
        } catch (JsonSchemaValidationException $e) {
            self::assertSame('Collection Of Json Models contains invalid data!', $e->getMessage());
            self::assertCanonicallySame(['1.vin' => ['Minimum string length is 17, found 7']], $e->errors());
        }
    }

    public function testInputIsCastOnPush()
    {
        $collection = new CollectionOfJsonModels();
        $collection->setType(Vehicle::class);

        $collection->push(['vin' => '11111111111111111']);

        self::assertInstanceOf(Vehicle::class, $collection[0]);
    }

    public function testCantPushWithoutSetType()
    {
        $collection = new CollectionOfJsonModels();
        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage("Can't add items to CollectionOfJsonModels until type has been set.");
        $collection->push('literally anything');
    }

    public function testPrimaryKeyIndexesCollection()
    {
        $collection = new CollectionOfJsonModels();
        $collection->setType(Vehicle::class)->setPrimaryKey('vin');

        $vehicle = Vehicle::factory()->make();
        $collection->push($vehicle);

        self::assertArrayHasKey($vehicle->vin, $collection);
        self::assertArrayNotHasKey(0, $collection);
        self::assertCount(1, $collection);
        self::assertSame($collection[$vehicle->vin], $vehicle);
        self::assertSame($collection->first(), $vehicle); // Not numeric, but still first-able

        $second = Vehicle::factory()->make(['vin' => '11111111111111112']);
        $collection->push($second);

        self::assertArrayHasKey($second->vin, $collection);
        self::assertArrayNotHasKey(1, $collection);
        self::assertCount(2, $collection);
        self::assertSame($collection[$second->vin], $second);
        self::assertSame($collection->last(), $second);
    }

    public function testCanRemoveByPrimaryKey()
    {
        $collection = new CollectionOfJsonModels();
        $collection->setType(Vehicle::class)->setPrimaryKey('vin');

        $vehicle = Vehicle::factory()->make();
        $collection->push($vehicle);
        self::assertCount(1, $collection);
        $collection->forget($vehicle->vin);
        self::assertCount(0, $collection);
        self::assertSame([], $collection->all());
    }

    public function testPushWithPrimaryKeyPreventsDuplicates()
    {
        $collection = new CollectionOfJsonModels();
        $collection->setType(Vehicle::class)->setPrimaryKey('vin');

        $collection->push(['vin' => '11111111111111111', 'extra' => false]);
        $collection->push(['vin' => '22222222222222222']);
        self::assertCount(2, $collection);
        self::assertFalse($collection->find('11111111111111111')->extra);

        $this->expectException(UniqueException::class);
        $this->expectExceptionMessage("Collection can't contain duplicate vin 11111111111111111");

        $collection->push(['vin' => '11111111111111111', 'extra' => true]);
    }

    public function testPushWithoutPrimaryKeyAllowsDuplicates()
    {
        $collection = new CollectionOfJsonModels();
        $collection->setType(Vehicle::class);

        $collection->push(['vin' => '11111111111111111']);
        $collection->push(['vin' => '22222222222222222']);
        $collection->push(['vin' => '11111111111111111']);

        self::assertCount(3, $collection);
        self::assertCount(2, $collection->where('vin', '11111111111111111'));
    }

    public function testCantSetTypeExceptJsonModel()
    {
        $collection = new CollectionOfJsonModels();
        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('CollectionOfJsonModels type must be a descendent of JsonModel');
        $collection->setType(\stdClass::class);
    }

    /**
     * Collection implementation of features like ->sortBy returns a new
     * Collection instance, but that means our new private variables like
     * $this->upstream_model won't be preserved, breaking ->isLinked
     */
    public function testSortBreaksLink()
    {
        $collection = new CollectionOfJsonModels();
        $collection->setType(Vehicle::class);

        $collection->push(['vin' => '22222222222222222']);
        $collection->push(['vin' => '11111111111111111']);

        $sorted = $collection->sortBy('vin');

        self::assertInstanceOf(CollectionOfJsonModels::class, $sorted);
        self::assertNotSame($sorted, $collection, 'sortBy returns a totally new object');
        self::assertFalse(
            $sorted->isLinked(),
            "object returned from sortBy doesn't inherit protected attributes from original",
        );

        // The sort itself worked though
        self::assertSame('11111111111111111', $sorted->first()->vin);
        self::assertSame('22222222222222222', $collection->first()->vin);
    }

    public function testCantFindWithoutPrimaryKey()
    {
        $collection = new CollectionOfJsonModels();
        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('Cannot use method find until primary key has been set.');
        $collection->find(1);
    }

    public function testFindSucceeds()
    {
        $collection = new CollectionOfJsonModels();
        $collection->setType(Vehicle::class)->setPrimaryKey('vin');

        $collection->push(['vin' => '22222222222222222']);
        $collection->push(['vin' => '11111111111111111']);

        $found = $collection->find('11111111111111111');
        self::assertInstanceOf(Vehicle::class, $found);
        self::assertSame('11111111111111111', $found->vin);
    }

    public function testFindFails()
    {
        $collection = new CollectionOfJsonModels();
        $collection->setType(Vehicle::class)->setPrimaryKey('vin');

        $collection->push(['vin' => '22222222222222222']);
        $collection->push(['vin' => '11111111111111111']);

        $found = $collection->find('33333333333333333');
        self::assertNull($found);
    }

    public function testFindOrFailSucceeds()
    {
        $collection = new CollectionOfJsonModels();
        $collection->setType(Vehicle::class)->setPrimaryKey('vin');

        $collection->push(['vin' => '22222222222222222']);
        $collection->push(['vin' => '11111111111111111']);

        $found = $collection->find('11111111111111111');
        self::assertInstanceOf(Vehicle::class, $found);
        self::assertSame('11111111111111111', $found->vin);
    }

    public function testFindOrFailFail()
    {
        $collection = new CollectionOfJsonModels();
        $collection->setType(Vehicle::class)->setPrimaryKey('vin');

        $collection->push(['vin' => '22222222222222222']);
        $collection->push(['vin' => '11111111111111111']);

        $this->expectException(ModelNotFoundException::class);
        $this->expectExceptionMessage('No query results for model');
        $collection->findOrFail('99999999999999999');
    }

    public function testSaveCollectionPreventedByChildSavingListener()
    {
        $model = mock(Model::class)->makePartial();
        $jsonModel = new EventedJsonModel();
        $jsonModel->saving_returns = false;

        $collection = new CollectionOfJsonModels();
        $collection->setType(EventedJsonModel::class)->link($model, 'attribute');
        $collection->push($jsonModel);

        // Saves should be rejected by the observer and never reach the model
        $model
            ->shouldReceive('save')
            ->never()
            ->andReturn(false);
        $status = $collection->save();
        self::assertFalse($status);
        self::assertNull($model->saving_fired);
        self::assertNull($model->saved_fired);
        self::assertSame(1, $jsonModel->saving_fired);
        self::assertSame(0, $jsonModel->saved_fired);
    }

    public function testSaveCollectionCallsChildSavedAndSavingListener()
    {
        $model = new EventedModelWithJsonAttributes();

        $collectionItem = new EventedJsonModel();
        $model->things->push($collectionItem);
        self::assertSame(0, $collectionItem->saving_fired);
        self::assertSame(0, $collectionItem->saved_fired);
        self::assertSame(0, $model->saving_fired);
        self::assertSame(0, $model->saved_fired);

        $status = $model->things->save();
        self::assertTrue($status);
        self::assertSame(1, $collectionItem->saving_fired);
        self::assertSame(1, $collectionItem->saved_fired);
        self::assertSame(1, $model->saving_fired);
        self::assertSame(1, $model->saved_fired);
    }

    public function testSaveCollectionCallsChildCreatingAndCreatedListeners()
    {
        $model = new EventedModelWithJsonAttributes();
        $collectionItem = new EventedJsonModel();
        $model->things->push($collectionItem);
        self::assertSame(0, $collectionItem->creating_fired);
        self::assertSame(0, $collectionItem->created_fired);

        $status = $model->things->save();
        self::assertTrue($status);
        self::assertSame(1, $collectionItem->creating_fired);
        self::assertSame(1, $collectionItem->created_fired);
    }

    public function testElementsThatExistWhenCollectionIsCreatedExist()
    {
        $model = mock(Model::class)->makePartial();
        $jsonModel = new class extends JsonModel {};
        $model->attribute = [['already' => 'exists']];
        $collection = new CollectionOfJsonModels();
        $collection
            ->setType(get_class($jsonModel))
            ->link($model, 'attribute')
            ->fresh();
        self::assertCount(1, $collection);
        self::assertTrue($collection[0]->exists);
    }

    public function testSaveCollectionSkipsCreatingListenerForExistingChildren()
    {
        $model = mock(Model::class)->makePartial();
        $model->attribute = [['already' => 'exists']];
        $collection = new CollectionOfJsonModels();
        $collection
            ->setType(EventedJsonModel::class)
            ->link($model, 'attribute')
            ->fresh();
        $existingJsonModel = $collection->first();
        self::assertTrue($existingJsonModel->exists);

        $newJsonModel = new EventedJsonModel();
        $collection->push($newJsonModel);
        self::assertFalse($newJsonModel->exists);

        $model
            ->shouldReceive('save')
            ->once()
            ->andReturn(true);

        $status = $collection->save();
        self::assertTrue($status);
        self::assertSame(1, $newJsonModel->creating_fired);
        self::assertSame(1, $newJsonModel->created_fired);
        self::assertSame(0, $existingJsonModel->creating_fired);
        self::assertSame(0, $existingJsonModel->created_fired);
    }

    public function testCascadeEventsToItems()
    {
        $model = mock(Model::class)->makePartial();
        $model
            ->shouldReceive('save')
            ->once()
            ->andReturn(true);
        $collection = new CollectionOfJsonModels();
        $collection->setType(EventedJsonModel::class);
        $collection->link($model, 'data');

        $first = new EventedJsonModel();
        $collection->push($first);
        $second = new EventedJsonModel();
        $collection->push($second);

        // Saving on the *collection* cascades events down to items
        $saveStatus = $collection->save();
        self::assertTrue($saveStatus);

        self::assertSame(1, $first->creating_fired);
        self::assertSame(1, $first->saving_fired);
        self::assertSame(1, $first->saved_fired);
        self::assertSame(1, $first->created_fired);

        self::assertSame(1, $second->creating_fired);
        self::assertSame(1, $second->saving_fired);
        self::assertSame(1, $second->saved_fired);
        self::assertSame(1, $second->created_fired);
    }

    public function testCascadePreventsEventsToItems()
    {
        $model = mock(Model::class)->makePartial();
        $model
            ->shouldReceive('save')
            ->never() // Saving cancelled by child never gets back to model
            ->andReturn(true);
        $collection = new CollectionOfJsonModels();
        $collection->setType(EventedJsonModel::class);
        $collection->link($model, 'data');

        $first = new EventedJsonModel();
        $first->saving_returns = false;
        $collection->push($first);
        $second = new EventedJsonModel();
        $collection->push($second);

        // Saving on the *collection* cascades events down to items
        $saveStatus = $collection->save();
        self::assertFalse($saveStatus);

        self::assertSame(1, $first->creating_fired);
        self::assertSame(1, $first->saving_fired);
        self::assertSame(0, $first->saved_fired, 'Saving failed, saved should not be called');
        self::assertSame(0, $first->created_fired, 'Saving failed, created should not be called');

        self::assertSame(
            0,
            $second->creating_fired,
            'Previous sibling failed, this sibling fires no observers at all.',
        );
        self::assertSame(0, $second->saving_fired);
        self::assertSame(0, $second->saved_fired);
        self::assertSame(0, $second->created_fired);
    }

    public function testFillCastsToCollectionType(): void
    {
        $collection = new CollectionOfJsonModels();
        $collection->setType(JsonModelVehicle::class)->setPrimaryKey('vin');
        $collection->fill([['vin' => '99999999999999999'], ['vin' => '88888888888888888']]);
        self::assertSame(2, $collection->count());
        $collection->each(function ($item) {
            self::assertInstanceOf(JsonModelVehicle::class, $item);
        });
        self::assertNotNull($collection->find('99999999999999999'));
        self::assertNotNull($collection->find('88888888888888888'));
    }
}

class JsonModelVehicle extends JsonModel
{
    public const SCHEMA = "vehicle.json";
}
