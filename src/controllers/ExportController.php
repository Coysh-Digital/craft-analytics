<?php

namespace coyshdigital\craftanalytics\controllers;

use coyshdigital\craftanalytics\models\DateRange;
use coyshdigital\craftanalytics\Plugin;
use craft\models\Site;
use yii\web\Response;

/**
 * CSV and JSON export.
 *
 * Exports carry exact values, not the abbreviated ones the UI shows — the
 * screens round for readability, files are for working with.
 */
class ExportController extends BaseCpController
{
    public function beforeAction($action): bool
    {
        if (!parent::beforeAction($action)) {
            return false;
        }

        $this->requirePermission(Plugin::PERMISSION_EXPORT);

        return true;
    }

    public function actionPages(): Response
    {
        $site = $this->currentSite();
        $siteId = $this->siteId($site);
        $range = $this->range();

        $rows = array_map(static fn(array $page): array => [
            'path' => $page['path'],
            'views' => $page['views'],
            'entrances' => $page['entrances'],
            'exits' => $page['exits'],
            'bounces' => $page['bounces'],
            'bounceRate' => round($page['bounceRate'], 2),
            'avgTimeOnPageSeconds' => (int)round($page['avgDwellMs'] / 1000),
        ], $this->stats()->topPages($siteId, $range, 5000));

        return $this->deliver('pages', $site, $range, $rows);
    }

    public function actionSources(): Response
    {
        $site = $this->currentSite();
        $siteId = $this->siteId($site);
        $range = $this->range();

        $rows = array_map(static fn(array $source): array => [
            'channel' => $source['channel'],
            'referrerHost' => $source['host'],
            'sessions' => $source['sessions'],
            'bounces' => $source['bounces'],
            'bounceRate' => round($source['bounceRate'], 2),
        ], $this->stats()->sources($siteId, $range, 5000));

        return $this->deliver('sources', $site, $range, $rows);
    }

    public function actionDevices(): Response
    {
        $site = $this->currentSite();
        $siteId = $this->siteId($site);
        $range = $this->range();
        $stats = $this->stats();
        $rows = [];

        foreach (['browser', 'os', 'deviceType'] as $dimension) {
            foreach ($stats->devices($siteId, $range, $dimension) as $row) {
                $rows[] = [
                    'dimension' => $dimension,
                    'value' => $row['label'],
                    'sessions' => $row['sessions'],
                ];
            }
        }

        return $this->deliver('devices', $site, $range, $rows);
    }

    /**
     * The trend as a table — the same numbers the dashboard chart plots, so
     * the chart is never the only way to read them.
     */
    public function actionTrend(): Response
    {
        $site = $this->currentSite();
        $siteId = $this->siteId($site);
        $range = $this->range();
        $trend = $this->stats()->trend($siteId, $range);

        $rows = [];
        foreach ($trend['labels'] as $i => $label) {
            $rows[] = [
                'period' => $label,
                'views' => $trend['views'][$i] ?? 0,
                'uniqueVisitors' => $trend['uniques'][$i] ?? 0,
            ];
        }

        return $this->deliver('trend', $site, $range, $rows);
    }

    /**
     * Traffic by section, entry type and author in one file — the same three
     * tables the Content screen shows, distinguished by a `dimension` column.
     */
    public function actionContent(): Response
    {
        $site = $this->currentSite();
        $siteId = $this->siteId($site);
        $range = $this->range();
        $content = Plugin::getInstance()->getContentStats();
        $rows = [];

        foreach ($content->bySection($siteId, $range, 5000) as $row) {
            $rows[] = [
                'dimension' => 'section',
                'value' => $row['name'],
                'views' => $row['views'],
                'entries' => $row['entries'],
            ];
        }

        foreach ($content->byEntryType($siteId, $range, 5000) as $row) {
            $rows[] = [
                'dimension' => 'entryType',
                'value' => $row['section'] !== '' ? $row['section'] . ' / ' . $row['name'] : $row['name'],
                'views' => $row['views'],
                'entries' => $row['entries'],
            ];
        }

        foreach ($content->byAuthor($siteId, $range, 5000) as $row) {
            $rows[] = [
                'dimension' => 'author',
                'value' => $row['name'],
                'views' => $row['views'],
                'entries' => $row['entries'],
            ];
        }

        return $this->deliver('content', $site, $range, $rows);
    }

    public function actionGoals(): Response
    {
        $site = $this->currentSite();
        $siteId = $this->siteId($site);
        $range = $this->range();

        if (!Plugin::getInstance()->is(Plugin::EDITION_PRO)) {
            throw new \yii\web\ForbiddenHttpException('Goals are a Craft Analytics Pro feature.');
        }

        $rows = array_map(static fn(array $goal): array => [
            'goal' => $goal['name'],
            'handle' => $goal['handle'],
            'type' => $goal['type'],
            'conversions' => $goal['conversions'],
            'value' => round($goal['value'], 2),
            'conversionRate' => round($goal['rate'], 2),
        ], Plugin::getInstance()->getConversionStats()->goals($siteId, $range));

        return $this->deliver('goals', $site, $range, $rows);
    }

    /**
     * @param array<int,array<string,mixed>> $rows
     */
    private function deliver(string $kind, Site $site, DateRange $range, array $rows): Response
    {
        $filename = sprintf('craft-analytics-%s-%s-%s-to-%s', $kind, $site->handle, $range->from, $range->to);

        if ($this->request->getParam('format') === 'json') {
            return $this->asJson([
                'site' => $site->handle,
                'from' => $range->from,
                'to' => $range->to,
                // Stated in the payload so a consumer can't mistake an
                // estimate for a count.
                'uniquesAccuracy' => $this->stats()->uniquesAccuracy(),
                'rows' => $rows,
            ]);
        }

        return $this->asCsv($rows, $filename);
    }

    /**
     * @param array<int,array<string,mixed>> $rows
     */
    private function asCsv(array $rows, string $filename): Response
    {
        $handle = fopen('php://temp', 'r+');

        if ($handle === false) {
            throw new \RuntimeException('Could not open a stream for the export.');
        }

        if ($rows !== []) {
            fputcsv($handle, array_keys($rows[0]), ',', '"', '\\');

            foreach ($rows as $row) {
                fputcsv($handle, array_values($row), ',', '"', '\\');
            }
        }

        rewind($handle);
        $csv = (string)stream_get_contents($handle);
        fclose($handle);

        return $this->response->sendContentAsFile($csv, $filename . '.csv', [
            'mimeType' => 'text/csv',
        ]);
    }
}
