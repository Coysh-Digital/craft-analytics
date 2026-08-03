<?php

namespace coyshdigital\craftanalytics\console\controllers;

use coyshdigital\craftanalytics\ingest\Hit;
use coyshdigital\craftanalytics\models\Campaign;
use coyshdigital\craftanalytics\Plugin;
use coyshdigital\craftanalytics\rollup\Aggregator;
use coyshdigital\craftanalytics\rollup\GoalMatcher;
use coyshdigital\craftanalytics\session\Session;
use coyshdigital\craftanalytics\session\SessionDelta;
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

    /**
     * Journeys people take on purpose.
     *
     * Left to chance, a random walk over the site's paths almost never
     * produces a visitor who lands on the blog, reads a post and then reaches
     * the contact page in that order - so every funnel would report a
     * completion rate near zero and the screen would look broken rather than
     * informative. Real sites have both: most visitors wander, and a minority
     * arrive meaning to do something.
     *
     * `*` picks a random path under that prefix.
     *
     * @var array<int,array{0: array<int,string>, 1: int}> steps, weight
     */
    private const JOURNEYS = [
        [['/blog', '/blog/*', '/contact'], 12],
        [['/blog', '/blog/*', '/blog/*'], 22],
        [['/blog', '/blog/*', '/about', '/contact'], 6],
        [['/guides', '/guides/*', '/contact'], 8],
        [['/guides/*', '/guides/*'], 10],
        [['/work', '/work/*', '/contact'], 5],
        [['/pricing', '/contact'], 9],
        [['/', '/services', '/contact'], 7],
        [['/', '/pricing', '/about', '/contact'], 4],
    ];

    /** Share of sessions that follow a journey rather than wandering. */
    private const JOURNEY_SHARE = 16;

    /**
     * Routes that exist without being entries: a template-only page, a search
     * results page. Real sites have these, and they are the reason the Pages
     * report and the Content report never quite agree - so the demo data
     * should contain some rather than presenting a site where every URL is
     * tidily an entry.
     *
     * @var array<string,int> path => weight
     */
    private const NON_ENTRY_PATHS = [
        '/search?q=analytics' => 3,
        '/search?q=pricing' => 2,
        '/search?q=gdpr' => 1,
        '/sitemap' => 1,
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

    /** @var array<string,int> event name => weight */
    private const EVENTS = [
        'newsletter-signup' => 26,
        'quote-requested' => 8,
        'video-play' => 18,
        'faq-expand' => 22,
        'pricing-toggle' => 14,
        'chat-opened' => 12,
    ];

    /** @var array<string,int> */
    private const OUTBOUND = [
        'https://craftcms.com/' => 22,
        'https://github.com/coyshdigital/craft-analytics' => 16,
        'https://plugins.craftcms.com/craft-analytics' => 12,
        'https://x.com/coyshdigital' => 9,
        'https://gdpr.eu/what-is-gdpr/' => 6,
    ];

    /** @var array<string,int> */
    private const DOWNLOADS = [
        'https://example.test/media/craft-analytics-overview.pdf' => 14,
        'https://example.test/media/dpia-template.docx' => 6,
        'https://example.test/media/pricing-2026.pdf' => 9,
    ];

    /**
     * Crawlers, and roughly how many requests each makes a day.
     *
     * A real site sees far more of this than most people realise, which is the
     * whole reason the Crawlers screen exists. These are excluded from every
     * other number.
     *
     * @var array<string,int>
     */
    private const CRAWLERS = [
        'Googlebot' => 180,
        'Bingbot' => 90,
        'GPTBot' => 45,
        'AhrefsBot' => 38,
        'SemrushBot' => 30,
        'Applebot' => 18,
        'YandexBot' => 12,
        'DuckDuckBot' => 7,
        'UptimeRobot' => 144,
    ];

    /**
     * Countries and regions, weighted. Only a country code and a region ever
     * exist here - the same two fields real capture keeps after resolving an
     * address in memory and throwing it away.
     *
     * @var array<int,array{0: string, 1: string, 2: int}>
     */
    private const LOCATIONS = [
        ['GB', 'England', 34],
        ['GB', 'Scotland', 5],
        ['GB', 'Wales', 3],
        ['US', 'California', 12],
        ['US', 'New York', 8],
        ['US', 'Texas', 4],
        ['DE', 'Berlin', 6],
        ['NL', 'North Holland', 5],
        ['FR', 'Ile-de-France', 4],
        ['AU', 'New South Wales', 4],
        ['CA', 'Ontario', 3],
        ['IE', 'Leinster', 3],
        ['SE', 'Stockholm', 2],
        ['ES', 'Madrid', 2],
        ['JP', 'Tokyo', 2],
        ['IN', 'Maharashtra', 3],
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
        // Quieter at weekends, with a slow upward trend over the period -
        // flat random noise looks nothing like a real site.
        $weekday = (int)$day->format('N');
        $weekendFactor = $weekday >= 6 ? 0.55 : 1.0;
        $growth = 0.75 + (1 - (int)$day->diff(new \DateTimeImmutable('now'))->days / max(1, $this->days)) * 0.5;
        $target = (int)round($this->perDay * $weekendFactor * $growth * random_int(85, 115) / 100);

        $aggregator = new Aggregator(new \DateTimeZone(Craft::$app->getTimeZone()), Plugin::getInstance()->getSettings());
        $sessions = [];
        $visitorCount = max(1, (int)round($target / 2.4));

        for ($v = 0; $v < $visitorCount; $v++) {
            $visitorHash = bin2hex(random_bytes(8));
            $sessionKey = substr(hash('sha256', $visitorHash), 0, 32);
            $hour = self::weightedHour();
            $start = $day->setTime($hour, random_int(0, 59), random_int(0, 59));
            $pageCount = self::weightedPageCount();
            $referrer = self::weightedString(self::REFERRERS);
            $userAgent = self::weightedString(self::AGENTS);
            $campaign = $this->weightedCampaign($referrer);
            [$country, $region] = self::weightedLocation();

            $journey = random_int(1, 100) <= self::JOURNEY_SHARE ? $this->journey($siteId) : null;

            if ($journey !== null) {
                $pageCount = count($journey);
            }

            $entryPath = $journey[0] ?? self::weightedString($this->paths($siteId));
            $lastPath = $entryPath;
            $cursor = $start;

            /** @var Hit[] $hits */
            $hits = [];

            for ($p = 0; $p < $pageCount; $p++) {
                $path = $journey[$p]
                    ?? ($p === 0 ? $entryPath : self::weightedString($this->paths($siteId)));
                $lastPath = $path;
                $dwell = self::dwellFor($path);

                $hits[] = new Hit(
                    siteId: $siteId,
                    path: $path,
                    visitorHash: $visitorHash,
                    sessionKey: $sessionKey,
                    timestamp: $cursor->getTimestamp(),
                    // Real capture gets this from the matched element; here we
                    // resolve it from the URI so the Content reports, the entry
                    // sidebar and the index column have something to show.
                    elementId: $this->elementIdForPath($siteId, $path),
                    referrer: $p === 0 ? $referrer : '',
                    userAgent: $userAgent,
                    dwellMs: $dwell,
                    // Only the first page carries the campaign: it describes
                    // how they arrived, not where they went next.
                    campaign: $p === 0 ? $campaign : null,
                    countryCode: $country,
                    region: $region,
                    scrollBucket: self::weightedScroll(),
                );

                $cursor = $cursor->modify('+' . (int)round($dwell / 1000) . ' seconds');

                foreach ($this->interactionsFor($siteId, $path, $visitorHash, $sessionKey, $cursor) as $hit) {
                    $hits[] = $hit;
                }
            }

            foreach ($hits as $hit) {
                // The session's referrer, not the hit's: only the first page
                // of a visit carries one above, which is how real capture
                // behaves. Passing it for every hit is what the drain does,
                // and without it every interior page would seed as Direct.
                $aggregator->add($hit, $referrer);
            }

            $sessions[] = $this->sessionFor($hits, [
                'siteId' => $siteId,
                'sessionKey' => $sessionKey,
                'visitorHash' => $visitorHash,
                'startedAt' => $start->getTimestamp(),
                'lastSeenAt' => $cursor->getTimestamp(),
                'pageviews' => $pageCount,
                'entryPath' => $entryPath,
                'lastPath' => $lastPath,
                'referrer' => $referrer,
                'userAgent' => $userAgent,
                'campaign' => $campaign,
                'countryCode' => $country,
                'region' => $region,
            ]);
        }

        $this->seedCrawlers($aggregator, $siteId, $day);

        $sink->flush($aggregator->buckets(), $sessions, $aggregator->interactions);
    }

    /**
     * Builds the session, with its goal conversions decided by the real
     * matcher rather than by a second, guessed-at copy of the rules.
     *
     * If the seeded goals and the live ones ever disagreed, the demo data
     * would be lying about the feature it exists to demonstrate.
     *
     * @param Hit[] $hits
     * @param array<string,mixed> $attributes
     */
    private function sessionFor(array $hits, array $attributes): Session
    {
        $delta = null;

        foreach ($hits as $hit) {
            if ($delta === null) {
                $delta = SessionDelta::fromHit($hit);
            } else {
                $delta->add($hit);
            }
        }

        if ($delta !== null) {
            $this->goalMatcher()->matchBatch($hits, [
                $attributes['siteId'] . ':' . $attributes['sessionKey'] => $delta,
            ]);
        }

        /** @var \coyshdigital\craftanalytics\models\Campaign|null $campaign */
        $campaign = $attributes['campaign'];

        return new Session(
            siteId: $attributes['siteId'],
            sessionKey: $attributes['sessionKey'],
            visitorHash: $attributes['visitorHash'],
            startedAt: $attributes['startedAt'],
            lastSeenAt: $attributes['lastSeenAt'],
            pageviews: $attributes['pageviews'],
            entryPath: $attributes['entryPath'],
            lastPath: $attributes['lastPath'],
            referrer: $attributes['referrer'],
            userAgent: $attributes['userAgent'],
            campaigns: $campaign !== null ? [$campaign->toArray()] : [],
            countryCode: $attributes['countryCode'],
            region: $attributes['region'],
            goals: $delta?->goals ?? [],
            maxScroll: $delta?->maxScroll ?? 0,
        );
    }

    /**
     * The Pro interactions that happen on a page: an event, an outbound click,
     * a download, a search.
     *
     * @return Hit[]
     */
    private function interactionsFor(
        int $siteId,
        string $path,
        string $visitorHash,
        string $sessionKey,
        \DateTimeImmutable $at,
    ): array {
        $hits = [];

        $make = static fn(string $kind, ?string $eventName, ?float $value, ?string $target): Hit => new Hit(
            siteId: $siteId,
            path: $path,
            visitorHash: $visitorHash,
            sessionKey: $sessionKey,
            timestamp: $at->getTimestamp(),
            countView: false,
            kind: $kind,
            eventName: $eventName,
            eventValue: $value,
            target: $target,
        );

        // Most pageviews produce no interaction at all. A site where every
        // visitor clicks something is not a site.
        if (random_int(1, 100) <= 12) {
            $event = self::weightedString(self::EVENTS);
            $hits[] = $make(
                Hit::KIND_EVENT,
                $event,
                $event === 'quote-requested' ? (float)random_int(120, 900) : null,
                null,
            );
        }

        if (random_int(1, 100) <= 6) {
            $hits[] = $make(Hit::KIND_OUTBOUND, null, null, self::weightedString(self::OUTBOUND));
        }

        if (random_int(1, 100) <= 3) {
            $hits[] = $make(Hit::KIND_DOWNLOAD, null, null, self::weightedString(self::DOWNLOADS));
        }

        return $hits;
    }

    /**
     * Crawler traffic, which is a large share of any real site's requests and
     * the reason the Crawlers screen exists.
     */
    private function seedCrawlers(Aggregator $aggregator, int $siteId, \DateTimeImmutable $day): void
    {
        foreach (self::CRAWLERS as $name => $perDay) {
            $requests = (int)round($perDay * random_int(60, 140) / 100);

            for ($i = 0; $i < $requests; $i++) {
                $aggregator->add(new Hit(
                    siteId: $siteId,
                    path: '/',
                    visitorHash: 'crawler000000000',
                    sessionKey: 'crawler000000000',
                    timestamp: $day->setTime(random_int(0, 23), random_int(0, 59))->getTimestamp(),
                    countView: false,
                    kind: Hit::KIND_CRAWLER,
                    eventName: $name,
                ));
            }
        }
    }

    /**
     * One purposeful journey, with its wildcards resolved to real paths.
     *
     * @return array<int,string>|null
     */
    private function journey(int $siteId): ?array
    {
        $weights = [];

        foreach (self::JOURNEYS as $index => [, $weight]) {
            $weights[$index] = $weight;
        }

        $steps = self::JOURNEYS[(int)self::weighted($weights)][0];
        $paths = array_keys($this->paths($siteId));
        $resolved = [];

        foreach ($steps as $step) {
            if (!str_ends_with($step, '*')) {
                $resolved[] = $step;
                continue;
            }

            $prefix = rtrim($step, '*');
            $candidates = array_values(array_filter(
                $paths,
                static fn(string $p): bool => str_starts_with($p, $prefix) && $p !== $prefix,
            ));

            if ($candidates === []) {
                // Nothing published under that prefix - the journey cannot be
                // walked, so it is dropped rather than half-invented.
                return null;
            }

            $resolved[] = $candidates[random_int(0, count($candidates) - 1)];
        }

        return $resolved;
    }

    private ?GoalMatcher $matcher = null;

    private function goalMatcher(): GoalMatcher
    {
        return $this->matcher ??= new GoalMatcher(
            Plugin::getInstance()->getGoals(),
            Plugin::getInstance()->is(Plugin::EDITION_PRO),
        );
    }

    /**
     * A campaign, for the traffic that arrived from one.
     *
     * Only for sessions with no organic referrer or a social one: a visitor
     * who arrived from a Google search did not arrive from your newsletter,
     * and tagging them both would make the Campaigns report disagree with the
     * Sources report.
     */
    private function weightedCampaign(string $referrer): ?Campaign
    {
        if (random_int(1, 100) > 22) {
            return null;
        }

        $campaigns = [
            ['newsletter', 'email', 'monthly-roundup'],
            ['newsletter', 'email', 'product-launch'],
            ['twitter', 'social', 'launch-week'],
            ['linkedin', 'social', 'thought-leadership'],
            ['google', 'cpc', 'brand'],
            ['google', 'cpc', 'competitor-terms'],
            ['craftcms', 'referral', 'plugin-store'],
            ['podcast', 'audio', 'sponsorship'],
        ];

        [$source, $medium, $name] = $campaigns[random_int(0, count($campaigns) - 1)];

        return Campaign::fromQueryString(http_build_query([
            'utm_source' => $source,
            'utm_medium' => $medium,
            'utm_campaign' => $name,
        ]));
    }

    /**
     * The paths to seed, weighted, discovered from the site's own entries.
     *
     * Built from what is actually published rather than from a list baked in
     * here, so the Content reports have real sections, entry types and authors
     * to join to - and so adding an entry to the demo site is enough to make
     * it appear in the seeded traffic.
     *
     * @var array<string,int>|null path => weight
     */
    private ?array $paths = null;

    /**
     * @return array<string,int>
     */
    private function paths(int $siteId): array
    {
        if ($this->paths !== null) {
            return $this->paths;
        }

        $paths = ['/' => 24];

        /** @var array<int,array{uri: string, section: string}> $rows */
        $rows = (new \craft\db\Query())
            ->select(['uri' => '[[es]].[[uri]]', 'section' => '[[s]].[[handle]]'])
            ->from(['es' => \craft\db\Table::ELEMENTS_SITES])
            ->innerJoin(['e' => \craft\db\Table::ENTRIES], '[[e]].[[id]] = [[es]].[[elementId]]')
            ->innerJoin(['el' => \craft\db\Table::ELEMENTS], '[[el]].[[id]] = [[es]].[[elementId]]')
            ->leftJoin(['s' => \craft\db\Table::SECTIONS], '[[s]].[[id]] = [[e]].[[sectionId]]')
            ->where(['[[es]].[[siteId]]' => $siteId])
            ->andWhere(['not', ['[[es]].[[uri]]' => null]])
            ->andWhere(['[[el]].[[dateDeleted]]' => null])
            ->andWhere(['[[el]].[[draftId]]' => null])
            ->andWhere(['[[el]].[[revisionId]]' => null])
            ->all();

        foreach ($rows as $row) {
            $uri = (string)$row['uri'];

            if ($uri === '__home__') {
                continue;
            }

            // Popularity is long-tailed and section-dependent: the blog index
            // and a couple of posts carry most of it, and a case study nobody
            // links to gets a handful of views a week. Flat weights produce a
            // report where every row is the same length, which looks wrong
            // precisely because it is.
            $paths['/' . $uri] = match ($row['section']) {
                'blog' => random_int(2, 14),
                'guides' => random_int(2, 10),
                'caseStudies' => random_int(1, 5),
                default => random_int(3, 12),
            };
        }

        foreach (self::NON_ENTRY_PATHS as $path => $weight) {
            $paths[$path] = $weight;
        }

        return $this->paths = $paths;
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

    /**
     * @return array{0: string, 1: string} country code, region
     */
    private static function weightedLocation(): array
    {
        $total = array_sum(array_column(self::LOCATIONS, 2));
        $roll = random_int(1, $total);

        foreach (self::LOCATIONS as [$country, $region, $weight]) {
            $roll -= $weight;

            if ($roll <= 0) {
                return [$country, $region];
            }
        }

        return ['GB', 'England'];
    }

    /**
     * How long somebody spends on a page, which depends entirely on what the
     * page is.
     *
     * One range for everything makes every page's average converge on the same
     * number - the demo site read 1m 32s on all fourteen of its top pages,
     * which is not a thing that happens and looks exactly as generated as it
     * was. A guide is read for minutes; a contact page is glanced at and
     * acted on.
     */
    private static function dwellFor(string $path): int
    {
        [$min, $max] = match (true) {
            str_starts_with($path, '/guides/') => [45_000, 420_000],
            str_starts_with($path, '/blog/') => [30_000, 300_000],
            str_starts_with($path, '/work/') => [25_000, 180_000],
            str_starts_with($path, '/contact') => [8_000, 70_000],
            str_starts_with($path, '/search') => [3_000, 25_000],
            str_starts_with($path, '/pricing') => [15_000, 150_000],
            $path === '/blog', $path === '/guides', $path === '/work' => [6_000, 60_000],
            $path === '/' => [5_000, 55_000],
            default => [10_000, 110_000],
        };

        // Each page gets its own character, derived from its path so it is the
        // same every day. Without this, every page of a type converges on the
        // same average - all fourteen blog posts read for exactly 2m00s - and
        // the report says "generated" rather than "measured". Real posts differ
        // from each other because they are different lengths about different
        // things.
        $factor = 0.6 + (hexdec(substr(md5($path), 0, 4)) % 100) / 100;

        // Skewed towards the short end: most visits to any page are brief, and
        // a symmetric spread puts the average in a place no real page sits.
        $roll = min(random_int($min, $max), random_int($min, $max));

        return (int)round($roll * $factor);
    }

    /**
     * How far down the page they read. Most people do not reach the bottom,
     * and a seeder that pretends otherwise makes the scroll report useless.
     */
    private static function weightedScroll(): int
    {
        return (int)self::weighted([25 => 38, 50 => 28, 75 => 20, 100 => 14]);
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
