<?php

namespace Tests;

use Carsdotcom\LaravelJsonModel\Helpers\Json;
use Carsdotcom\LaravelJsonModel\JsonModel;
use Orchestra\Testbench\TestCase;
use ReflectionObject;

class BaseTestCase extends TestCase
{
    public function setUp(): void
    {
        parent::setUp();
        JsonModel::setEventDispatcher($this->app['events']);
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        JsonModel::clearBootedModels();
    }

    /**
     * Given two things that support json encoding,
     * assert that they are identical in their canonicalized (sorted props), stringified form
     * @param mixed $a
     * @param mixed $b
     */
    public static function assertCanonicallySame($a, $b, string $comment = ''): void
    {
        $cannonA = Json::canonicalize(json_encode($a), JSON_PRETTY_PRINT);
        $cannonB = Json::canonicalize(json_encode($b), JSON_PRETTY_PRINT);
        self::assertSame($cannonA, $cannonB, $comment);
    }

    /**
     * Define environment setup.
     *
     * @param  \Illuminate\Foundation\Application  $app
     * @return void
     */
    protected function defineEnvironment($app)
    {
        // Setup default database to use sqlite :memory:
        $app['config']->set('json-schema.base_url', 'https://schemas.dealerinspire.com/online-shopper/');
        $app['config']->set('json-schema.local_base_prefix', dirname(__FILE__) . '/Schemas');
        $app['config']->set('json-schema.local_base_prefix_tests', dirname(__FILE__) . '/Schemas');
        $app['config']->set('database.connections.testbench', [
            'driver'   => 'sqlite',
            'database' => ':memory:',
            'prefix'   => '',
        ]);
    }
}