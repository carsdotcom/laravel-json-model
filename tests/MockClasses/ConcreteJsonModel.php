<?php

/**
 * Make JsonModel concrete so it can more easily be tested.
 * This is an easy implementation, because JsonModel doesn't have any abstract methods.
 */

declare(strict_types=1);

namespace Tests\MockClasses;

use Carsdotcom\LaravelJsonModel\JsonModel;

/**
 * Class ConcreteJsonModel
 * @package Tests\Concrete
 */
class ConcreteJsonModel extends JsonModel
{
}
