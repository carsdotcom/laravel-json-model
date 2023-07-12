<?php
/**
 * A custom interface that makes it easier to provide hints for the usage of this function
 */
declare(strict_types=1);

namespace Tests;

use Mockery\Expectation;
use Mockery\MockInterface;

/**
 * Interface CustomMockInterface
 * @package Tests
 */
interface CustomMockInterface extends MockInterface
{
    /**
     * @param array ...$function
     * @return Expectation
     */
    public function shouldReceive(...$function);
}