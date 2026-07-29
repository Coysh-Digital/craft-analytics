<?php

use coyshdigital\craftanalytics\models\Settings;
use coyshdigital\craftanalytics\tests\TestDb;
use coyshdigital\craftanalytics\uniques\ExactUniqueCounter;
use coyshdigital\craftanalytics\uniques\HllUniqueCounter;
use coyshdigital\craftanalytics\uniques\RedisUniqueCounter;
use yii\redis\Connection as RedisConnection;

uses()->in('Unit', 'Integration');

/**
 * A Redis connection for the driver tests, or null when this run has none
 * configured — the redis driver is then skipped rather than failing.
 *
 * Database 15, flushed on connect: never point these at anything real.
 */
function redisConnection(): ?RedisConnection
{
    $dsn = getenv('CRAFT_ANALYTICS_TEST_REDIS_HOST');

    if ($dsn === false || $dsn === '') {
        return null;
    }

    $connection = new RedisConnection([
        'hostname' => $dsn,
        'port' => (int)(getenv('CRAFT_ANALYTICS_TEST_REDIS_PORT') ?: 6379),
        'database' => 15,
    ]);
    $connection->open();
    $connection->executeCommand('FLUSHDB');

    return $connection;
}

/**
 * Every unique-counter driver, so a contract test can be written once and held
 * against all three.
 *
 * Lives here rather than beside one test file because more than one suite
 * needs it: the driver contract, and the compaction cycle that broke two of
 * these drivers without the contract tests noticing.
 *
 * @return array<string,callable(): ?coyshdigital\craftanalytics\uniques\UniqueCounterInterface>
 */
dataset('counters', [
    'hll' => [fn() => new HllUniqueCounter(['settings' => new Settings()])],
    'exact' => [fn() => new ExactUniqueCounter(['db' => TestDb::connection(), 'settings' => new Settings()])],
    'redis' => [function() {
        $connection = redisConnection();

        return $connection === null ? null : new RedisUniqueCounter([
            'redis' => $connection,
            'settings' => new Settings(),
        ]);
    }],
]);
