<?php

namespace coyshdigital\craftanalytics\services;

use coyshdigital\craftanalytics\db\Table;
use coyshdigital\craftanalytics\enums\DimensionType;
use Craft;
use yii\base\Component;
use yii\db\Connection;
use yii\db\IntegrityException;
use yii\db\Query;

/**
 * Get-or-create lookups against the shared dimensions table.
 *
 * This sits on the drain hot path: every rollup write resolves its dimension
 * values to int IDs here, so lookups are memoized per request/process and the
 * table is only touched on first sight of a value.
 */
class DimensionsService extends Component
{
    /** Dimension values are truncated to the storage column length. */
    private const MAX_VALUE_LENGTH = 500;

    /**
     * Connection override — set for tests, and later for the Pro external
     * analytics database. Defaults to Craft's primary connection.
     */
    public ?Connection $db = null;

    /** @var array<string,int> */
    private array $memo = [];

    /**
     * Returns the dimension ID for a value, inserting it on first sight.
     */
    public function getOrCreateId(DimensionType $type, string $value): int
    {
        $value = self::normalize($value);
        $hash = self::valueHash($value);
        $memoKey = $type->value . ':' . $hash;

        if (isset($this->memo[$memoKey])) {
            return $this->memo[$memoKey];
        }

        $id = $this->fetchId($type, $hash) ?? $this->insert($type, $hash, $value);

        return $this->memo[$memoKey] = $id;
    }

    /**
     * Returns the dimension ID for a value, or null if it has never been seen.
     */
    public function getId(DimensionType $type, string $value): ?int
    {
        $value = self::normalize($value);

        return $this->fetchId($type, self::valueHash($value));
    }

    /**
     * 64-bit xxHash of the value, hex-encoded for cross-database index safety.
     */
    public static function valueHash(string $value): string
    {
        return hash('xxh3', $value);
    }

    public static function normalize(string $value): string
    {
        if (mb_strlen($value) > self::MAX_VALUE_LENGTH) {
            $value = mb_substr($value, 0, self::MAX_VALUE_LENGTH);
        }

        return $value;
    }

    private function fetchId(DimensionType $type, string $hash): ?int
    {
        $id = (new Query())
            ->select('id')
            ->from(Table::DIMENSIONS)
            ->where(['type' => $type->value, 'valueHash' => $hash])
            ->scalar($this->db());

        return $id === false ? null : (int)$id;
    }

    private function insert(DimensionType $type, string $hash, string $value): int
    {
        try {
            $this->db()->createCommand()->insert(Table::DIMENSIONS, [
                'type' => $type->value,
                'valueHash' => $hash,
                'value' => $value,
                'firstSeen' => gmdate('Y-m-d'),
            ])->execute();
        } catch (IntegrityException) {
            // Lost a get-or-create race with a concurrent writer; the row
            // exists now.
        }

        $id = $this->fetchId($type, $hash);

        if ($id === null) {
            throw new \RuntimeException('Failed to persist dimension value.');
        }

        return $id;
    }

    private function db(): Connection
    {
        return $this->db ??= Craft::$app->getDb();
    }
}
