<?php

/**
 * A JsonModel that holds a collection keyed by `id`
 */

declare(strict_types=1);

namespace Tests\MockClasses;

use Carsdotcom\LaravelJsonModel\CollectionOfJsonModels;
use Carsdotcom\LaravelJsonModel\JsonModel;
use Carsdotcom\LaravelJsonModel\Traits\HasJsonModelAttributes;

class JsonModelWithKeyedItems extends JsonModel
{
    use HasJsonModelAttributes;

    public const SCHEMA = 'loose_json_model.json';

    protected $jsonModelAttributes = [
        'items' => [LooseJsonModel::class, 'items', null, CollectionOfJsonModels::IS_A, 'id'],
    ];
}
