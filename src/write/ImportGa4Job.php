<?php

namespace coyshdigital\craftanalytics\write;

use coyshdigital\craftanalytics\enums\Ga4Dataset;
use coyshdigital\craftanalytics\Plugin;
use coyshdigital\craftanalytics\services\Ga4Client;
use coyshdigital\craftanalytics\services\Ga4Exception;
use Craft;
use craft\queue\BaseJob;

/**
 * Backfills history from a GA4 property into the rollups.
 *
 * Runs on Craft's queue because it is slow and network-bound - dozens of API
 * calls across a date range - and nothing a visitor should ever wait for. The
 * work itself is {@see Ga4ImportService}'s; this walks the datasets and the
 * pages of each report and hands the rows over.
 *
 * Idempotent by construction on retry: each dataset writes inside a
 * transaction, so a mid-dataset failure rolls back cleanly, and the whole run
 * skips any day the plugin already has rollups for - including days an earlier
 * attempt of this same job wrote, since those days now carry pages and sessions
 * rows.
 */
class ImportGa4Job extends BaseJob
{
    public string $propertyId = '';
    public int $siteId = 0;

    /** Inclusive date range, 'Y-m-d', which GA4 accepts verbatim. */
    public string $startDate = '';
    public string $endDate = '';

    /**
     * The wizard groups chosen, e.g. ['pages', 'sources'].
     *
     * @var string[]
     */
    public array $groups = [];

    public function execute($queue): void
    {
        $plugin = Plugin::getInstance();
        $isPro = $plugin->is(Plugin::EDITION_PRO);
        $client = $plugin->getGa4Client();
        $import = $plugin->getGa4Import();

        // Pro datasets on a Lite install target rollups the reports there do not
        // read, so they are dropped - exactly as live capture never writes them.
        $datasets = array_values(array_filter(
            Ga4Dataset::forGroups($this->groups),
            static fn(Ga4Dataset $d): bool => $isPro || !$d->isPro(),
        ));

        if ($datasets === []) {
            return;
        }

        // The overlap policy: never import over a day the plugin already
        // measured. Computed once, up front.
        $skipDates = $import->occupiedDates($this->siteId, $this->startDate, $this->endDate);

        $total = count($datasets);
        $errors = [];

        foreach ($datasets as $index => $dataset) {
            $this->setProgress(
                $queue,
                $index / $total,
                Craft::t('craft-analytics', 'Importing {dataset}', ['dataset' => $dataset->value]),
            );

            try {
                $this->importDataset($client, $import, $dataset, $skipDates);
            } catch (Ga4Exception $e) {
                // One failed dataset should not lose the ones that worked, so
                // the run carries on and reports at the end.
                $errors[$dataset->value] = $e->getMessage();
                Craft::warning(
                    "craft-analytics GA4 import: {$dataset->value} failed: {$e->getMessage()}",
                    __METHOD__,
                );
            }
        }

        $this->setProgress($queue, 1);

        if ($errors !== []) {
            $summary = implode('; ', array_map(
                static fn(string $k, string $v): string => "$k: $v",
                array_keys($errors),
                array_values($errors),
            ));

            throw new Ga4Exception(Craft::t(
                'craft-analytics',
                'Some datasets could not be imported ({summary}). Re-running skips what already came across.',
                ['summary' => $summary],
            ));
        }
    }

    /**
     * @param array<string,true> $skipDates
     */
    private function importDataset(
        Ga4Client $client,
        \coyshdigital\craftanalytics\services\Ga4ImportService $import,
        Ga4Dataset $dataset,
        array $skipDates,
    ): void {
        $db = Craft::$app->getDb();
        $transaction = $db->beginTransaction();

        try {
            $offset = 0;

            do {
                $response = $client->runReport(
                    $this->propertyId,
                    $dataset,
                    $this->startDate,
                    $this->endDate,
                    Ga4Client::REPORT_PAGE_SIZE,
                    $offset,
                );

                $import->import($dataset, $this->siteId, $response, $skipDates);

                $rowCount = (int)($response['rowCount'] ?? 0);
                $offset += Ga4Client::REPORT_PAGE_SIZE;
                // GA4 reports the full rowCount on every page; stop once the
                // window has passed it.
            } while ($offset < $rowCount);

            $transaction->commit();
        } catch (\Throwable $e) {
            $transaction->rollBack();
            throw $e;
        }
    }

    protected function defaultDescription(): ?string
    {
        return Craft::t('craft-analytics', 'Importing analytics history from Google Analytics 4');
    }
}
