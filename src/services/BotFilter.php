<?php

namespace coyshdigital\craftanalytics\services;

use coyshdigital\craftanalytics\models\Settings;
use coyshdigital\craftanalytics\Plugin;
use Jaybizzle\CrawlerDetect\CrawlerDetect;
use yii\base\Component;

/**
 * Bot and crawler exclusion.
 *
 * Two layers: the MIT-licensed CrawlerDetect UA list, plus heuristics for
 * automation that doesn't announce itself. An optional third layer reads a
 * verdict a CDN/WAF has already stamped on the request, for the data-centre
 * traffic that spoofs a browser UA and no token can catch. Everything here
 * runs on data already in memory - no network calls (C7), no IP storage (C5).
 *
 * Precision matters more than recall: wrongly labelling a person a bot silently
 * deletes them from the numbers, which is worse than counting the odd crawler.
 * That is why a missing Accept-Language is *not* treated as a bot signal - real
 * in-app browsers and privacy tools omit it.
 */
class BotFilter extends Component
{
    /**
     * Headless/automation markers that CrawlerDetect's crawler list doesn't
     * cover — these UAs belong to real browser engines being driven.
     */
    private const AUTOMATION_MARKERS = [
        'headlesschrome',
        'phantomjs',
        'electron/',
        'puppeteer',
        'playwright',
        'selenium',
        'webdriver',
        'cypress',
        'lighthouse',
        'chrome-lighthouse',
        'pagespeed',
    ];

    /**
     * Name given to a request the edge verdict flagged but no UA token did — a
     * browser-spoofing client the network caught. Bounded and generic on
     * purpose: the point is the count, not which data centre it came from.
     */
    private const EDGE_CRAWLER_NAME = 'Data-centre (edge)';

    public ?Settings $settings = null;

    private ?CrawlerDetect $detector = null;

    /**
     * @param array<string,string> $headers lowercase header name => value
     */
    public function isBot(string $userAgent, array $headers = [], bool $clientWebdriver = false): bool
    {
        if ($clientWebdriver) {
            return true;
        }

        if ($userAgent === '') {
            // Real browsers always send a UA; its absence is a stronger bot
            // signal than any list.
            return true;
        }

        if ($this->detector()->isCrawler($userAgent)) {
            return true;
        }

        $ua = strtolower($userAgent);
        foreach (self::AUTOMATION_MARKERS as $marker) {
            if (str_contains($ua, $marker)) {
                return true;
            }
        }

        // Optional second signal: a bot score the edge (Cloudflare, a WAF) has
        // already worked out from the network and stamped on a header. Off
        // unless the site names the header. This catches the impersonators a
        // spoofable UA cannot.
        return $this->edgeVerdictIsBot($headers);
    }

    /**
     * What to call this crawler in the report.
     *
     * CrawlerDetect gives back the matched fragment of the user agent, which
     * is close enough to a name ("Googlebot", "bingbot") and, importantly, is
     * bounded: the alternative is storing whole user-agent strings, which is
     * unbounded cardinality and mild fingerprinting for no gain.
     *
     * @param array<string,string> $headers lowercase header name => value
     */
    public function crawlerName(string $userAgent, array $headers = []): string
    {
        if ($userAgent === '') {
            return 'Unknown';
        }

        $this->detector()->isCrawler($userAgent);
        $matched = trim((string)$this->detector()->getMatches());

        if ($matched !== '') {
            return ucfirst(substr($matched, 0, 100));
        }

        $ua = strtolower($userAgent);

        foreach (self::AUTOMATION_MARKERS as $marker) {
            if (str_contains($ua, $marker)) {
                return ucfirst($marker);
            }
        }

        // No UA token matched, so the edge verdict is what flagged it.
        if ($this->edgeVerdictIsBot($headers)) {
            return self::EDGE_CRAWLER_NAME;
        }

        // Reached only if the caller asks for a name without a match - keep a
        // stable, bounded label rather than leaking the raw UA.
        return 'Unrecognised automation';
    }

    /**
     * Whether an edge-stamped bot score says this is a bot.
     *
     * The site names the header (`botScoreHeader`) and the cut-off
     * (`botScoreThreshold`); a score at or below the cut-off is a bot. Absent
     * header or unset feature means no opinion - never a false positive.
     *
     * @param array<string,string> $headers lowercase header name => value
     */
    private function edgeVerdictIsBot(array $headers): bool
    {
        $settings = $this->settings();
        $header = strtolower(trim($settings->botScoreHeader));

        if ($header === '' || !isset($headers[$header]) || $headers[$header] === '') {
            return false;
        }

        if (!is_numeric($headers[$header])) {
            return false;
        }

        return (int)$headers[$header] <= $settings->botScoreThreshold;
    }

    private function settings(): Settings
    {
        return $this->settings ??= Plugin::getInstance()->getSettings();
    }

    private function detector(): CrawlerDetect
    {
        return $this->detector ??= new CrawlerDetect();
    }
}
