<?php

namespace Carsdotcom\LaravelJsonModel\Casts;

interface ShouldUnset
{
    static function shouldUnset(mixed $value): bool;
}