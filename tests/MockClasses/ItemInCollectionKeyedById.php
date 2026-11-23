<?php

/**
 * A JsonModel whose collections are CollectionKeyedById
 */

declare(strict_types=1);

namespace Tests\MockClasses;

use Carsdotcom\LaravelJsonModel\JsonModel;

class ItemInCollectionKeyedById extends JsonModel
{
    public const SCHEMA = 'loose_json_model.json';

    public function newCollection(array $models = []): CollectionKeyedById
    {
        return (new CollectionKeyedById($models))->setType(static::class);
    }
}
