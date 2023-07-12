<?php

/**
 * Check if the given class uses the given trait.
 */

declare(strict_types=1);

namespace Carsdotcom\LaravelJsonModel\Helpers;

class ClassUsesTrait
{
    public function __invoke($class, string $trait): bool
    {
        return in_array($trait, class_uses_recursive($class), true);
    }
}
