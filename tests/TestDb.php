<?php

namespace coyshdigital\craftanalytics\tests;

use craft\config\DbConfig;
use craft\db\Connection;
use craft\helpers\App;

/**
 * Builds a real craft\db\Connection from the CRAFT_ANALYTICS_TEST_* env vars.
 *
 * Driver selection: CRAFT_ANALYTICS_TEST_DRIVER (mysql|pgsql). Integration
 * tests are skipped when the matching DSN env var is absent, so unit tests
 * always run without any database.
 */
final class TestDb
{
    private static ?Connection $connection = null;

    public static function driver(): ?string
    {
        $driver = getenv('CRAFT_ANALYTICS_TEST_DRIVER') ?: null;

        return in_array($driver, ['mysql', 'pgsql'], true) ? $driver : null;
    }

    public static function dsn(): ?string
    {
        return match (self::driver()) {
            'mysql' => getenv('CRAFT_ANALYTICS_TEST_MYSQL_DSN') ?: null,
            'pgsql' => getenv('CRAFT_ANALYTICS_TEST_PGSQL_DSN') ?: null,
            default => null,
        };
    }

    public static function available(): bool
    {
        return self::dsn() !== null;
    }

    public static function connection(): Connection
    {
        if (self::$connection !== null) {
            return self::$connection;
        }

        $dsn = self::dsn();

        if ($dsn === null) {
            throw new \RuntimeException('Test database is not configured.');
        }

        $user = (self::driver() === 'pgsql' ? getenv('CRAFT_ANALYTICS_TEST_PGSQL_USER') : null)
            ?: getenv('CRAFT_ANALYTICS_TEST_DB_USER') ?: 'root';

        $config = App::dbConfig(new DbConfig([
            'dsn' => $dsn,
            'user' => $user,
            'password' => getenv('CRAFT_ANALYTICS_TEST_DB_PASSWORD') ?: '',
            'tablePrefix' => 'test_',
        ]));

        // Query logging/profiling routes through Craft::debug(), which needs
        // a booted app — off for tests.
        $config['enableLogging'] = false;
        $config['enableProfiling'] = false;
        $config['enableSchemaCache'] = false;
        $config['schemaCache'] = null;

        /** @var Connection $connection */
        $connection = \Yii::createObject($config);
        $connection->open();

        return self::$connection = $connection;
    }

    /**
     * Drops every table the tests may have created, for a clean slate.
     *
     * @param string[] $tables table names in {{%...}} form
     */
    public static function dropTables(array $tables): void
    {
        $db = self::connection();
        foreach ($tables as $table) {
            if ($db->getTableSchema($table, true) !== null) {
                $db->createCommand()->dropTable($table)->execute();
            }
        }
    }
}
