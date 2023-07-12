<?php

namespace Tests\Mocks\Models;

use Carsdotcom\LaravelJsonModel\JsonModel;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Vehicle extends JsonModel
{
    use HasFactory;

    public const SCHEMA = "vehicle.json";
}