<?php

/**
 * Json Model that, when not set, does not hydrate into the Json Model Attribute cache
 * and instead returns null to the attribute getter.
 */
declare(strict_types=1);

namespace Tests\MockClasses;

use Carsdotcom\LaravelJsonModel\JsonModel;
use Carsdotcom\LaravelJsonModel\Traits\NullWhenUsedAsAttributeWhenEmpty;

/**
 * Class ConcreteNullableJsonModel
 * @package Tests\MockClasses
 */
class ConcreteNullableJsonModel extends JsonModel
{
    use NullWhenUsedAsAttributeWhenEmpty;
}
