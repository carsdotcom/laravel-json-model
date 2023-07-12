<?php
/**
 * This is useful in unit tests where you would otherwise pass an anonymous function.
 *
 * Instead you can:
 * $like_a_function = mock(Invokeable::class);
 * $like_a_function->shouldReceive('__invoke')
 *     ->once()
 *     ->with('some','args')
 *     ->andReturn(true);
 */

declare(strict_types=1);

namespace Tests\MockClasses;

/**
 * Class Invokeable
 * @package Tests\MockClasses
 */
class Invokeable
{
    public function __invoke()
    {
    }
}
