<?php

namespace coyshdigital\craftanalytics\rollup;

use coyshdigital\craftanalytics\db\Table;
use coyshdigital\craftanalytics\enums\DimensionType;
use coyshdigital\craftanalytics\models\Settings;
use coyshdigital\craftanalytics\Plugin;
use coyshdigital\craftanalytics\services\DimensionsService;
use Craft;
use yii\base\Component;
use yii\db\Connection;
use yii\db\Query;

/**
 * Keeps unbounded dimensions from exploding the rollups.
 *
 * Paths and referrer hosts have no natural ceiling: one misbehaving crawler
 * hitting `/search?q=<random>` a million times would otherwise mean a million
 * dimension rows and a million rollup rows — traffic-driven growth, which is
 * exactly what C2 forbids. So each (site, date, type) admits at most
 * `dimensionCap` distinct values and folds everything after into a single
 * reserved `__other__` dimension.
 *
 * **First-N, not top-N.** The cap admits the first N distinct values seen on
 * a day, because at the moment a value first appears there is no way to know
 * whether it will end the day popular. In practice the cap only ever engages
 * during a cardinality explosion — a normal site never approaches 1,000
 * distinct paths in a day — and in that situation the tail being folded away
 * *is* the junk. The docs say this plainly rather than implying a ranking
 * that isn't there.
 *
 * Browsers, OSes and channels are naturally bounded and are not capped.
 */
class DimensionCapper extends Component
{
    /**
     * Where each capped dimension type is already recorded, so the day's
     * distinct values can be counted without a separate tracking table.
     *
     * @var array<int,array{0: string, 1: string}> type => [table, column]
     */
    private const CAPPED_TYPES = [
        DimensionType::Path->value => [Table::PAGES_ROLLUP, 'pathDimId'],
        DimensionType::ReferrerHost->value => [Table::SOURCES_ROLLUP, 'refHostDimId'],
        // A site declares its segment *keys*, but not necessarily their
        // values — an undeclared value list means the site's own code decides,
        // and code with a bug in it can decide a great many times.
        DimensionType::Segment->value => [Table::SEGMENTS_ROLLUP, 'segmentDimId'],
    ];

    public ?Connection $db = null;
    public ?Settings $settings = null;
    public ?DimensionsService $dimensions = null;

    /** @var array<string,array<int,true>> "siteId|date|type" => admitted dim IDs */
    private array $admitted = [];

    /** @var array<int,int> type => `__other__` dimension ID */
    private array $otherIds = [];

    /**
     * Resolves a value to the dimension ID the rollup should use — either its
     * own, or `__other__` if the day's cap is already spent.
     */
    public function resolve(int $siteId, string $date, DimensionType $type, string $value): int
    {
        if (!isset(self::CAPPED_TYPES[$type->value])) {
            return $this->dimensions()->getOrCreateId($type, $value);
        }

        $dimId = $this->dimensions()->getOrCreateId($type, $value);
        $key = $siteId . '|' . $date . '|' . $type->value;
        $admitted = $this->admitted[$key] ??= $this->loadAdmitted($siteId, $date, $type);

        // Already counted today: it stays individually tracked for the rest
        // of the day, cap or no cap.
        if (isset($admitted[$dimId])) {
            return $dimId;
        }

        if (count($admitted) >= $this->settings()->dimensionCap) {
            return $this->otherId($type);
        }

        $this->admitted[$key][$dimId] = true;

        return $dimId;
    }

    public function otherId(DimensionType $type): int
    {
        return $this->otherIds[$type->value] ??= $this->dimensions()
            ->getOrCreateId($type, DimensionType::OTHER_VALUE);
    }

    /**
     * Distinct values already recorded for this (site, date, type).
     *
     * @return array<int,true>
     */
    private function loadAdmitted(int $siteId, string $date, DimensionType $type): array
    {
        [$table, $column] = self::CAPPED_TYPES[$type->value];

        $ids = (new Query())
            ->select($column)
            ->distinct()
            ->from($table)
            ->where(['siteId' => $siteId, 'date' => $date])
            ->column($this->db());

        $admitted = [];
        foreach ($ids as $id) {
            $admitted[(int)$id] = true;
        }

        // `__other__` occupies a row but is not one of the tracked values —
        // otherwise the cap would shrink by one the moment it engaged.
        unset($admitted[$this->otherId($type)]);

        return $admitted;
    }

    private function dimensions(): DimensionsService
    {
        return $this->dimensions ??= Plugin::getInstance()->getDimensions();
    }

    private function settings(): Settings
    {
        return $this->settings ??= Plugin::getInstance()->getSettings();
    }

    private function db(): Connection
    {
        return $this->db ??= Craft::$app->getDb();
    }
}
