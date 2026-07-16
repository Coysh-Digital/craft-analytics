<?php

namespace coyshdigital\craftanalytics\services;

use Jaybizzle\CrawlerDetect\CrawlerDetect;
use yii\base\Component;

/**
 * Bot and crawler exclusion.
 *
 * Two layers: the MIT-licensed CrawlerDetect UA list, plus heuristics for
 * automation that doesn't announce itself. Everything here runs on data
 * already in memory — no network calls (C7), no IP storage (C5).
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

        // Every real browser sends Accept-Language on a document request;
        // most simple scripted clients don't.
        return !isset($headers['accept-language']) || $headers['accept-language'] === '';
    }

    private function detector(): CrawlerDetect
    {
        return $this->detector ??= new CrawlerDetect();
    }
}
