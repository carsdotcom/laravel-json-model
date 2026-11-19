<?php

/**
 * A custom collection class that declares its own primary key
 */

declare(strict_types=1);

namespace Tests\MockClasses;

use Carsdotcom\LaravelJsonModel\CollectionOfJsonModels;

class CollectionKeyedById extends CollectionOfJsonModels
{
    protected $primaryKey = 'id';
}
