<?php

namespace Tests\Mocks\Models;

use Carsdotcom\LaravelJsonModel\JsonModel;

/**
 * @property string $country
 */
class Address extends JsonModel
{
    public const SCHEMA = 'address.json';
}
