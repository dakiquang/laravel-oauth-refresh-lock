<?php

declare(strict_types=1);

namespace Dakiquang\OAuthRefreshLock\Tests;

use Dakiquang\OAuthRefreshLock\OAuthRefreshLockServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [OAuthRefreshLockServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('app.key', 'base64:'.base64_encode(random_bytes(32)));
        $app['config']->set('database.default', 'testing');
        $pgsqlHost = getenv('OAUTH_TEST_PGSQL_HOST');
        $app['config']->set('database.connections.testing', is_string($pgsqlHost) && $pgsqlHost !== '' ? [
            'driver' => 'pgsql',
            'host' => $pgsqlHost,
            'port' => getenv('OAUTH_TEST_PGSQL_PORT') ?: '5432',
            'database' => getenv('OAUTH_TEST_PGSQL_DATABASE') ?: 'testing',
            'username' => getenv('OAUTH_TEST_PGSQL_USER') ?: 'postgres',
            'password' => getenv('OAUTH_TEST_PGSQL_PASSWORD') ?: '',
            'charset' => 'utf8',
            'prefix' => '',
            'search_path' => 'public',
        ] : [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);
    }
}
