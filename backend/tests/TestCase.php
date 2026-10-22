<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

class TestCase extends BaseTestCase
{
    public function createApplication()
    {
        $app = parent::createApplication();
        // Container environment appears in both $_ENV and $_SERVER; PHPUnit's env
        // overrides alone cannot safely select a database when Compose injects one.
        $driver = getenv('CDRMAPP_TEST_DRIVER') ?: 'sqlite';
        if (! in_array($driver, ['sqlite', 'mysql'], true)) {
            throw new \RuntimeException('Unsupported test database.');
        }
        $app->instance('env', 'testing');
        $app['config']->set(['app.env' => 'testing', 'database.default' => $driver, 'database.connections.sqlite.database' => ':memory:', 'database.connections.sqlite.url' => null, 'database.connections.mysql.database' => 'cdrmapp_testing', 'database.connections.mysql.url' => null, 'cache.default' => 'array', 'session.driver' => 'array', 'queue.default' => 'sync', 'mail.default' => 'array', 'services.fcm.enabled' => false]);
        $app['db']->purge('mysql');
        $app['db']->purge('sqlite');
        $name = $app['db']->connection($driver)->getDatabaseName();
        if ($name !== ($driver === 'mysql' ? 'cdrmapp_testing' : ':memory:')) {
            throw new \RuntimeException('Refusing to migrate a non-test database.');
        }

        return $app;
    }
}
