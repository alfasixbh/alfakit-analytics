<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitAnalytics\Tests;

use AlfaSix\Alfakit\Tests\TestCase as AlfakitTestCase;
use AlfaSix\AlfakitAnalytics\AlfakitAnalyticsServiceProvider;
use RuntimeException;

abstract class TestCase extends AlfakitTestCase
{
    /**
     * The running test (Pest binds test closures to it).
     */
    public static function current(): static
    {
        $test = \Pest\TestSuite::getInstance()->test;

        if (! $test instanceof static) {
            throw new RuntimeException('No '.static::class.' is running.');
        }

        return $test;
    }

    protected function getPackageProviders($app): array
    {
        return [...parent::getPackageProviders($app), AlfakitAnalyticsServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('cache.default', 'array');
    }

    protected function defineDatabaseMigrations(): void
    {
        parent::defineDatabaseMigrations();

        $this->loadMigrationsFrom(dirname(__DIR__).'/database/migrations');
        $this->loadMigrationsFrom(dirname(__DIR__, 3).'/alfakit/database/migrations');
    }
}
