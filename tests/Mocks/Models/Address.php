<?php

namespace Tests\Mocks\Models;

use Carsdotcom\LaravelJsonModel\JsonModel;

/**
 * @property string $country
 * @property string $street
 */
class Address extends JsonModel
{
    public const SCHEMA = 'address.json';
}
