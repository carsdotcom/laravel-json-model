<?php

namespace Carsdotcom\LaravelJsonModel\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsInboundAttributes;

class CastStringOrUnset implements ShouldUnset, CastsInboundAttributes
{
    public static function shouldUnset(mixed $value): bool
    {
        return ($value === null);
    }

    public function set($model, string $key, $value, array $attributes)
    {
        if ($this->shouldUnset($value)) {
            return null;
        }
        return (string)$value;
    }
}