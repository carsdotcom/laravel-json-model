<?php
/**
 * The person model has a JsonModelAttribute of Address
 */

namespace Tests\Mocks\Models;

use Carsdotcom\LaravelJsonModel\JsonModel;
use Carsdotcom\LaravelJsonModel\Traits\HasJsonModelAttributes;
use Tests\MockClasses\EventedJsonModel;

/**
 * @property Address $address
 * @property string|null $first_name
 * @property string $middle_name
 * @property string $last_name
 * @property string $email
 */
class Person extends EventedJsonModel
{
    use HasJsonModelAttributes;
    public const SCHEMA = 'person.json';

    protected $jsonModelAttributes = [
        'address' => [Address::class, 'address'],
    ];
}
