<?php
/**
 * Integration tests (uses database) for HasLinkedData
 */
declare(strict_types=1);

namespace Tests\Unit\Traits;

use Illuminate\Database\Eloquent\Model;
use Tests\BaseTestCase;
use Tests\Mocks\Models\Address;
use Tests\Mocks\Models\Person;

/**
 * Class HasLinkedDataTest
 * @package Tests\Integration\Traits\Models
 */
class HasLinkedDataTest extends BaseTestCase
{
    /**
     * Testing that when Person (a JsonModel) saves,
     * it recursively saves changes from its children (including Address)
     */
    public function testSavingParentSavesCachedChildren()
    {
        $model = mock(Model::class)->makePartial();
        $model->person = ['first_name' => 'Jeremy', 'address' => ['country' => 'US']];
        $person = new Person($model, 'person');
        $model
            ->shouldReceive('save')
            ->once()
            ->andReturn(true);

        self::assertInstanceOf(Address::class, $person->address);
        $person->address->street = '123 Sesame St.';
        self::assertFalse(isset($model['person']['address']['street']));
        self::assertFalse(isset($person['address']['street']));
        self::assertTrue(isset($person->address->street));

        $person->save();
        self::assertTrue(isset($person['address']['street']), 'saved from Address to Person');
        self::assertCanonicallySame(
            ['country' => 'US', 'street' => '123 Sesame St.'],
            $model['person']['address'],
            'and saved from Person to the Eloquent Model',
        );
    }
}
