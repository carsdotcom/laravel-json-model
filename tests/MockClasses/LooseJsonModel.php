<?php

/**
 * A JsonModel that validates against a schema with no strict requirements
 */

declare(strict_types=1);

namespace Tests\MockClasses;

use Carsdotcom\LaravelJsonModel\JsonModel;

class LooseJsonModel extends JsonModel
{
    public const SCHEMA = 'loose_json_model.json';
}
