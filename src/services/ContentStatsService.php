<?php

namespace coyshdigital\craftanalytics\services;

use coyshdigital\craftanalytics\db\Table;
use coyshdigital\craftanalytics\models\DateRange;
use Craft;
use craft\db\Table as CraftTable;
use yii\base\Component;
use yii\db\Connection;
use yii\db\Query;

/**
 * Traffic by Craft's own content model: section, entry type, author.
 *
 * This is the thing a generic analytics tool structurally cannot do. Google
 * Analytics sees `/blog/why-privacy-first-analytics` and knows it is a
 * string. This plugin knows it is an Entry, in the Blog section, of the
 * Article type, written by Sam — because it recorded the element Craft
 * matched the request to, and Craft already knows the rest.
 *
 * **No new storage.** The rollups already carry `elementId`; everything here
 * is a join to Craft's own tables at query time. That means:
 *
 * - Nothing to keep in sync, and no drift between the analytics and the CMS.
 * - Re-sectioning an entry re-attributes its history, because the answer is
 *   computed from what is true now rather than from what was true then. That
 *   is the right default for a CMS: the question is "how is the Blog section
 *   performing", not "how did the pages that were in Blog last March perform".
 * - The join is cheap: the rollups are small by construction (C2), and
 *   `elementId` hits a primary key.
 */
class ContentStatsService extends Component
{
    public ?Connection $db = null;

    /**
     * Traffic by section.
     *
     * @return array<int,array{id: int, name: string, handle: string, views: int, entries: int, avgDwellMs: int}>
     */
    public function bySection(int $siteId, DateRange $range, int $limit = 100): array
    {
        $rows = $this->contentQuery($siteId, $range)
            ->select([
                'id' => '[[sec]].[[id]]',
                'name' => '[[sec]].[[name]]',
                'handle' => '[[sec]].[[handle]]',
                'views' => 'SUM([[p]].[[views]])',
                'entries' => 'COUNT(DISTINCT [[p]].[[elementId]])',
                'dwell' => 'SUM([[p]].[[totalDwellMs]])',
            ])
            ->innerJoin(['sec' => CraftTable::SECTIONS], '[[sec]].[[id]] = [[e]].[[sectionId]]')
            ->andWhere(['[[sec]].[[dateDeleted]]' => null])
            ->groupBy(['[[sec]].[[id]]', '[[sec]].[[name]]', '[[sec]].[[handle]]'])
            ->orderBy(['views' => SORT_DESC])
            ->limit($limit)
            ->all($this->db());

        return array_map(static fn(array $row): array => [
            'id' => (int)$row['id'],
            'name' => (string)$row['name'],
            'handle' => (string)$row['handle'],
            'views' => (int)$row['views'],
            'entries' => (int)$row['entries'],
            'avgDwellMs' => (int)$row['views'] > 0 ? (int)round((int)$row['dwell'] / (int)$row['views']) : 0,
        ], $rows);
    }

    /**
     * Traffic by entry type.
     *
     * @return array<int,array{id: int, name: string, section: string, views: int, entries: int}>
     */
    public function byEntryType(int $siteId, DateRange $range, int $limit = 100): array
    {
        $rows = $this->contentQuery($siteId, $range)
            ->select([
                'id' => '[[et]].[[id]]',
                'name' => '[[et]].[[name]]',
                'section' => '[[sec]].[[name]]',
                'views' => 'SUM([[p]].[[views]])',
                'entries' => 'COUNT(DISTINCT [[p]].[[elementId]])',
            ])
            ->innerJoin(['et' => CraftTable::ENTRYTYPES], '[[et]].[[id]] = [[e]].[[typeId]]')
            ->leftJoin(['sec' => CraftTable::SECTIONS], '[[sec]].[[id]] = [[e]].[[sectionId]]')
            ->andWhere(['[[et]].[[dateDeleted]]' => null])
            ->groupBy(['[[et]].[[id]]', '[[et]].[[name]]', '[[sec]].[[name]]'])
            ->orderBy(['views' => SORT_DESC])
            ->limit($limit)
            ->all($this->db());

        return array_map(static fn(array $row): array => [
            'id' => (int)$row['id'],
            'name' => (string)$row['name'],
            'section' => (string)($row['section'] ?? ''),
            'views' => (int)$row['views'],
            'entries' => (int)$row['entries'],
        ], $rows);
    }

    /**
     * Traffic by author — "who writes the pages people actually read".
     *
     * Craft 5 allows several authors per entry. Each gets the full view count
     * rather than a share of it: the question is "how do Sam's articles
     * perform", and an article Sam co-wrote is one of Sam's articles. Column
     * totals will therefore exceed site views where entries are co-authored,
     * which is correct and worth saying out loud.
     *
     * @return array<int,array{id: int, name: string, views: int, entries: int}>
     */
    public function byAuthor(int $siteId, DateRange $range, int $limit = 100): array
    {
        $rows = $this->contentQuery($siteId, $range)
            ->select([
                'id' => '[[u]].[[id]]',
                'name' => 'COALESCE([[u]].[[fullName]], [[u]].[[username]])',
                'views' => 'SUM([[p]].[[views]])',
                'entries' => 'COUNT(DISTINCT [[p]].[[elementId]])',
            ])
            ->innerJoin(['ea' => CraftTable::ENTRIES_AUTHORS], '[[ea]].[[entryId]] = [[e]].[[id]]')
            ->innerJoin(['u' => CraftTable::USERS], '[[u]].[[id]] = [[ea]].[[authorId]]')
            ->groupBy(['[[u]].[[id]]', '[[u]].[[fullName]]', '[[u]].[[username]]'])
            ->orderBy(['views' => SORT_DESC])
            ->limit($limit)
            ->all($this->db());

        return array_map(static fn(array $row): array => [
            'id' => (int)$row['id'],
            'name' => (string)($row['name'] ?? ''),
            'views' => (int)$row['views'],
            'entries' => (int)$row['entries'],
        ], $rows);
    }

    /**
     * The best-performing entries of one section — the drill-down from the
     * section report.
     *
     * @return array<int,array{elementId: int, title: string, views: int}>
     */
    public function entriesInSection(int $siteId, DateRange $range, int $sectionId, int $limit = 50): array
    {
        $rows = $this->contentQuery($siteId, $range)
            ->select([
                'elementId' => '[[p]].[[elementId]]',
                'title' => '[[es]].[[title]]',
                'views' => 'SUM([[p]].[[views]])',
            ])
            ->innerJoin(
                ['es' => CraftTable::ELEMENTS_SITES],
                '[[es]].[[elementId]] = [[p]].[[elementId]] AND [[es]].[[siteId]] = [[p]].[[siteId]]',
            )
            ->andWhere(['[[e]].[[sectionId]]' => $sectionId])
            ->groupBy(['[[p]].[[elementId]]', '[[es]].[[title]]'])
            ->orderBy(['views' => SORT_DESC])
            ->limit($limit)
            ->all($this->db());

        return array_map(static fn(array $row): array => [
            'elementId' => (int)$row['elementId'],
            'title' => (string)($row['title'] ?? ''),
            'views' => (int)$row['views'],
        ], $rows);
    }

    /**
     * The rollup rows that matched an entry, joined to Craft's own tables.
     *
     * Soft-deleted and draft/revision elements are excluded: a report about
     * "the Blog section" should not include the revision history of things in
     * it.
     */
    private function contentQuery(int $siteId, DateRange $range): Query
    {
        return (new Query())
            ->from(['p' => Table::PAGES_ROLLUP])
            ->innerJoin(['e' => CraftTable::ENTRIES], '[[e]].[[id]] = [[p]].[[elementId]]')
            ->innerJoin(['el' => CraftTable::ELEMENTS], '[[el]].[[id]] = [[p]].[[elementId]]')
            ->where(['[[p]].[[siteId]]' => $siteId])
            ->andWhere(['between', '[[p]].[[date]]', $range->from, $range->to])
            ->andWhere(['[[el]].[[dateDeleted]]' => null])
            ->andWhere(['[[el]].[[draftId]]' => null])
            ->andWhere(['[[el]].[[revisionId]]' => null]);
    }

    private function db(): Connection
    {
        return $this->db ??= Craft::$app->getDb();
    }
}
