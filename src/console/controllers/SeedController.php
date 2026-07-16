<?php

namespace coyshdigital\craftanalytics\console\controllers;

use coyshdigital\craftanalytics\ingest\Hit;
use coyshdigital\craftanalytics\Plugin;
use coyshdigital\craftanalytics\rollup\Aggregator;
use coyshdigital\craftanalytics\session\Session;
use Craft;
use craft\console\Controller;
use craft\helpers\Console;
use yii\console\ExitCode;

/**
 * Generates plausible traffic, for development and for looking at the CP with
 * something in it.
 *
 * Never for production: it writes straight to the rollups. It exists because
 * "does this screen work" and "does this screen work with 90 days of realistic
 * data in it" are different questions.
 */
class SeedController extends Controller
{
    /** Days of history to generate. */
    public int $days = 90;

    /** Roughly how many pageviews per day, before the weekly rhythm. */
    public int $perDay = 400;

    public function options($actionID): array
    {
        return array_merge(parent::options($actionID), ['days', 'perDay']);
    }

    private const PATHS = [
        '/' => 24,
        '/about' => 9,
        '/services' => 8,
        '/services/consulting' => 6,
        '/services/support' => 4,
        '/blog' => 10,
        '/blog/why-privacy-first-analytics' => 7,
        '/blog/measuring-without-cookies' => 5,
        '/blog/craft-cms-performance' => 4,
        '/pricing' => 8,
        '/contact' => 6,
        '/careers' => 3,
        '/legal/privacy' => 2,
    ];

    private const REFERRERS = [
        '' => 34,
        'https://www.google.com/search?q=x' => 26,
        'https://duckduckgo.com/' => 5,
        'https://news.ycombinator.com/' => 8,
        'https://x.com/someone/status/1' => 6,
        'https://www.linkedin.com/feed/' => 5,
        'https://craftcms.com/plugins' => 7,
        'https://example.org/blog/roundup' => 4,
    ];

    private const AGENTS = [
        'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.0.0 Safari/537.36' => 26,
        'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.0.0 Safari/537.36' => 22,
        'Mozilla/5.0 (iPhone; CPU iPhone OS 17_5 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.5 Mobile/15E148 Safari/604.1' => 20,
        'Mozilla/5.0 (Linux; Android 14; Pixel 8) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.0.0 Mobile Safari/537.36' => 12,
        'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.5 Safari/605.1.15' => 9,
        'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:127.0) Gecko/20100101 Firefox/127.0' => 6,
        'Mozilla/5.0 (iPad; CPU OS 17_5 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.5 Mobile/15E148 Safari/604.1' => 5,
    ];

    /**
     * Seed the rollups with generated traffic.
     */
    public function actionRun(): int
    {
        if (Craft::$app->getConfig()->getGeneral()->devMode === false) {
            $this->stderr("Refusing to seed outside dev mode.\n", Console::FG_RED);

            return ExitCode::CONFIG;
        }

        if (!$this->confirm("Write {$this->days} days of generated traffic into the rollups?", true)) {
            return ExitCode::OK;
        }

        $site = Craft::$app->getSites()->getPrimarySite();
        $siteId = $site->id;

        if ($siteId === null) {
            return ExitCode::CONFIG;
        }

        $sink = Plugin::getInstance()->getRollupSink();
        $timeZone = new \DateTimeZone(Craft::$app->getTimeZone());
        $today = (new \DateTimeImmutable('now', $timeZone))->setTime(0, 0);

        Console::startProgress(0, $this->days);

        for ($dayOffset = $this->days - 1; $dayOffset >= 0; $dayOffset--) {
            $day = $today->modify("-$dayOffset days");
            $this->seedDay($sink, $siteId, $day);
            Console::updateProgress($this->days - $dayOffset, $this->days);
        }

        Console::endProgress();
        $this->stdout("Seeded {$this->days} days of traffic.\n", Console::FG_GREEN);

        return ExitCode::OK;
    }

    private function seedDay(
        \coyshdigital\craftanalytics\rollup\RollupSinkInterface $sink,
        int $siteId,
        \DateTimeImmutable $day,
    ): void {
        // Quieter at weekends, with a slow upward trend over the period —
        // flat random noise looks nothing like a real site.
        $weekday = (int)$day->format('N');
        $weekendFactor = $weekday >= 6 ? 0.55 : 1.0;
        $growth = 0.75 + (1 - (int)$day->diff(new \DateTimeImmutable('now'))->days / max(1, $this->days)) * 0.5;
        $target = (int)round($this->perDay * $weekendFactor * $growth * random_int(85, 115) / 100);

        $aggregator = new Aggregator(new \DateTimeZone(Craft::$app->getTimeZone()));
        $sessions = [];
        $visitorCount = max(1, (int)round($target / 2.4));

        for ($v = 0; $v < $visitorCount; $v++) {
            $visitorHash = bin2hex(random_bytes(8));
            $hour = self::weightedHour();
            $start = $day->setTime($hour, random_int(0, 59), random_int(0, 59));
            $pageCount = self::weightedPageCount();
            $referrer = self::weightedString(self::REFERRERS);
            $userAgent = self::weightedString(self::AGENTS);

            $entryPath = self::weightedString(self::PATHS);
            $lastPath = $entryPath;
            $cursor = $start;

            for ($p = 0; $p < $pageCount; $p++) {
                $path = $p === 0 ? $entryPath : self::weightedString(self::PATHS);
                $lastPath = $path;
                $dwell = random_int(4000, 180000);

                $aggregator->add(new Hit(
                    siteId: $siteId,
                    path: $path,
                    visitorHash: $visitorHash,
                    sessionKey: substr(hash('sha256', $visitorHash), 0, 32),
                    timestamp: $cursor->getTimestamp(),
                    // Real capture gets this from the matched element; here we
                    // resolve it from the URI so the entry sidebar and index
                    // column have something to show.
                    elementId: $this->elementIdForPath($siteId, $path),
                    referrer: $p === 0 ? $referrer : '',
                    userAgent: $userAgent,
                    dwellMs: $dwell,
                ));

                $cursor = $cursor->modify('+' . (int)round($dwell / 1000) . ' seconds');
            }

            $sessions[] = new Session(
                siteId: $siteId,
                sessionKey: substr(hash('sha256', $visitorHash), 0, 32),
                visitorHash: $visitorHash,
                startedAt: $start->getTimestamp(),
                lastSeenAt: $cursor->getTimestamp(),
                pageviews: $pageCount,
                entryPath: $entryPath,
                lastPath: $lastPath,
                referrer: $referrer,
                userAgent: $userAgent,
            );
        }

        $sink->flush($aggregator->buckets(), $sessions);
    }

    /** @var array<string,int|null> path => elementId */
    private array $elementIds = [];

    private function elementIdForPath(int $siteId, string $path): ?int
    {
        if (!array_key_exists($path, $this->elementIds)) {
            $uri = $path === '/' ? '__home__' : ltrim($path, '/');
            $element = Craft::$app->getElements()->getElementByUri($uri, $siteId);
            $this->elementIds[$path] = $element?->id;
        }

        return $this->elementIds[$path];
    }

    /** Traffic clusters in the working day rather than spreading evenly. */
    private static function weightedHour(): int
    {
        $weights = [0 => 1, 1 => 1, 2 => 1, 3 => 1, 4 => 1, 5 => 2, 6 => 4, 7 => 7,
            8 => 11, 9 => 14, 10 => 15, 11 => 14, 12 => 11, 13 => 12, 14 => 14,
            15 => 13, 16 => 11, 17 => 9, 18 => 7, 19 => 6, 20 => 6, 21 => 5, 22 => 3, 23 => 2, ];

        return (int)self::weighted($weights);
    }

    /** Most sessions are one page; a few are long. */
    private static function weightedPageCount(): int
    {
        return (int)self::weighted([1 => 46, 2 => 22, 3 => 13, 4 => 8, 5 => 5, 6 => 3, 7 => 2, 9 => 1]);
    }

    /**
     * @param array<array-key,int> $weights value => weight
     */
    private static function weightedString(array $weights): string
    {
        return (string)self::weighted($weights);
    }

    /**
     * @param array<array-key,int> $weights value => weight
     */
    private static function weighted(array $weights): string|int
    {
        $total = array_sum($weights);
        $roll = random_int(1, max(1, $total));

        foreach ($weights as $value => $weight) {
            $roll -= $weight;

            if ($roll <= 0) {
                return $value;
            }
        }

        return array_key_first($weights) ?? '';
    }
}
