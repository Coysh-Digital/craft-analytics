<?php

namespace coyshdigital\craftanalytics\db;

use yii\db\Connection;
use yii\db\Expression;

/**
 * Cross-database counter upserts.
 *
 * All rollup writes go through here so the MySQL/Postgres difference lives in
 * exactly one place. Relies on the table having a unique index over the key
 * columns; counter columns are added to the existing row on conflict.
 */
final class Upsert
{
    /**
     * Insert a row, or increment its counter columns if the unique key exists.
     *
     * @param array<string,mixed> $keys key columns (covered by a unique index)
     * @param array<string,int|float> $counters columns accumulated on conflict
     * @param array<string,mixed> $extra columns written on insert only
     * @param string[] $fillIfNull $extra columns that may also fill an
     *                             existing row where the value is still null
     */
    public static function counters(
        Connection $db,
        string $table,
        array $keys,
        array $counters,
        array $extra = [],
        array $fillIfNull = [],
    ): void {
        $update = [];
        foreach (array_keys($counters) as $column) {
            $update[$column] = self::incrementExpression($db, $table, $column);
        }

        foreach ($fillIfNull as $column) {
            if (($extra[$column] ?? null) !== null) {
                $update[$column] = self::fillIfNullExpression($db, $table, $column);
            }
        }

        $db->createCommand()
            ->upsert($table, array_merge($keys, $counters, $extra), $update)
            ->execute();
    }

    /**
     * Upsert many rows in one transaction. Rows must all share the same
     * key/counter/extra column sets.
     *
     * @param array<int,array{keys: array<string,mixed>, counters: array<string,int|float>, extra?: array<string,mixed>}> $rows
     */
    public static function countersBatch(Connection $db, string $table, array $rows): void
    {
        if ($rows === []) {
            return;
        }

        $transaction = $db->beginTransaction();
        try {
            foreach ($rows as $row) {
                self::counters($db, $table, $row['keys'], $row['counters'], $row['extra'] ?? []);
            }
            $transaction->commit();
        } catch (\Throwable $e) {
            $transaction->rollBack();
            throw $e;
        }
    }

    /**
     * Driver-appropriate "existing + incoming" expression for a counter column.
     */
    private static function incrementExpression(Connection $db, string $table, string $column): Expression
    {
        $quotedColumn = $db->quoteColumnName($column);

        if ($db->getDriverName() === 'pgsql') {
            // In ON CONFLICT DO UPDATE, the existing row must be qualified with
            // the table name and the proposed row is EXCLUDED.
            $quotedTable = $db->quoteTableName($db->getSchema()->getRawTableName($table));

            return new Expression("$quotedTable.$quotedColumn + EXCLUDED.$quotedColumn");
        }

        return new Expression("$quotedColumn + VALUES($quotedColumn)");
    }

    /**
     * Driver-appropriate "keep what is there, unless it is null" expression.
     *
     * For columns that describe the row rather than count it, where a later
     * write may know something the row that created it did not. Insert-only
     * was not good enough for `elementId`: a page row first created by a
     * beacon (which carries no element) or by the entrance/exit write (which
     * never passed one) kept a null forever, and stayed invisible to every
     * report that joins through it - permanently, since nothing revisits it.
     *
     * Never overwrites a resolved value, so the first answer still wins.
     */
    private static function fillIfNullExpression(Connection $db, string $table, string $column): Expression
    {
        $quotedColumn = $db->quoteColumnName($column);
        $quotedTable = $db->quoteTableName($db->getSchema()->getRawTableName($table));

        if ($db->getDriverName() === 'pgsql') {
            return new Expression("COALESCE($quotedTable.$quotedColumn, EXCLUDED.$quotedColumn)");
        }

        return new Expression("COALESCE($quotedColumn, VALUES($quotedColumn))");
    }
}
