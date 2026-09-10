<?php

use coyshdigital\craftanalytics\models\Settings;
use coyshdigital\craftanalytics\services\BotFilter;

const HUMAN_HEADERS = ['accept-language' => 'en-GB,en;q=0.9'];

/**
 * A BotFilter with settings supplied directly. Plugin::getInstance() is null in
 * the unit harness, so the edge-signal settings must be injected rather than
 * resolved lazily. Defaults leave the edge signal off (blank header).
 */
function bot(?Settings $settings = null): BotFilter
{
    $filter = new BotFilter();
    $filter->settings = $settings ?? new Settings();

    return $filter;
}

/**
 * Real UA strings. Precision matters as much as recall here: wrongly
 * classifying a human as a bot silently deletes traffic from the numbers,
 * which is worse than counting the odd crawler. In-app browsers (a link tapped
 * inside Facebook or Instagram) are real people and the easiest to lose.
 */
dataset('humans', [
    'Chrome/Mac' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.0.0 Safari/537.36',
    'Safari/iPhone' => 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_5 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.5 Mobile/15E148 Safari/604.1',
    'Firefox/Windows' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:127.0) Gecko/20100101 Firefox/127.0',
    'Edge/Windows' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.0.0 Safari/537.36 Edg/126.0.0.0',
    'Chrome/Android' => 'Mozilla/5.0 (Linux; Android 14; Pixel 8) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.0.0 Mobile Safari/537.36',
    'Samsung Internet' => 'Mozilla/5.0 (Linux; Android 13; SAMSUNG SM-S918B) AppleWebKit/537.36 (KHTML, like Gecko) SamsungBrowser/23.0 Chrome/115.0.0.0 Mobile Safari/537.36',
    'Facebook in-app/iOS' => 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_5 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Mobile/15E148 [FBAN/FBIOS;FBAV/468.0.0.0;FBBV/1;FBDV/iPhone15,2;FBMD/iPhone;FBLC/en_GB]',
    'Instagram in-app/Android' => 'Mozilla/5.0 (Linux; Android 14; Pixel 8) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.0.0 Mobile Safari/537.36 Instagram 336.0.0.0 Android',
    'Android WebView' => 'Mozilla/5.0 (Linux; Android 14; Pixel 8; wv) AppleWebKit/537.36 (KHTML, like Gecko) Version/4.0 Chrome/126.0.0.0 Mobile Safari/537.36',
]);

dataset('bots', [
    'Googlebot' => 'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)',
    'bingbot' => 'Mozilla/5.0 (compatible; bingbot/2.0; +http://www.bing.com/bingbot.htm)',
    'AhrefsBot' => 'Mozilla/5.0 (compatible; AhrefsBot/7.0; +http://ahrefs.com/robot/)',
    'GPTBot' => 'Mozilla/5.0 AppleWebKit/537.36 (KHTML, like Gecko); compatible; GPTBot/1.0; +https://openai.com/gptbot',
    'curl' => 'curl/8.4.0',
    'python-requests' => 'python-requests/2.31.0',
    'UptimeRobot' => 'Mozilla/5.0+(compatible; UptimeRobot/2.0; http://www.uptimerobot.com/)',
]);

dataset('automation', [
    'HeadlessChrome' => 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) HeadlessChrome/126.0.0.0 Safari/537.36',
    'PhantomJS' => 'Mozilla/5.0 (Unknown; Linux x86_64) AppleWebKit/538.1 (KHTML, like Gecko) PhantomJS/2.1.1 Safari/538.1',
    'Lighthouse' => 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.0.0 Safari/537.36 Chrome-Lighthouse',
]);

test('real browsers are not filtered', function(string $ua) {
    expect(bot()->isBot($ua, HUMAN_HEADERS))->toBeFalse();
})->with('humans');

test('crawlers are filtered', function(string $ua) {
    expect(bot()->isBot($ua, HUMAN_HEADERS))->toBeTrue();
})->with('bots');

test('headless and automation drivers are filtered', function(string $ua) {
    expect(bot()->isBot($ua, HUMAN_HEADERS))->toBeTrue();
})->with('automation');

test('an empty user agent is filtered', function() {
    expect(bot()->isBot('', HUMAN_HEADERS))->toBeTrue();
});

test('a real browser without Accept-Language is not filtered', function() {
    // A missing Accept-Language is not a bot signal: real in-app browsers and
    // privacy tools omit it, and deleting them is worse than counting the odd
    // scripted client. Detection rests on the UA, not this header.
    $chrome = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.0.0 Safari/537.36';

    expect(bot()->isBot($chrome, []))->toBeFalse()
        ->and(bot()->isBot($chrome, ['accept-language' => '']))->toBeFalse();
});

test('a client reporting navigator.webdriver is filtered regardless of UA', function() {
    $chrome = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.0.0 Safari/537.36';

    expect(bot()->isBot($chrome, HUMAN_HEADERS, clientWebdriver: true))->toBeTrue();
});

/**
 * The edge second signal: a browser-spoofing client the network caught. Off
 * unless the site names the header, and never a false positive when it is off.
 */
test('an edge bot score at or below the threshold is filtered', function() {
    $settings = new Settings();
    $settings->botScoreHeader = 'cf-bot-score';
    $settings->botScoreThreshold = 30;

    $chrome = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.0.0 Safari/537.36';

    expect(bot($settings)->isBot($chrome, HUMAN_HEADERS + ['cf-bot-score' => '10']))->toBeTrue()
        ->and(bot($settings)->isBot($chrome, HUMAN_HEADERS + ['cf-bot-score' => '30']))->toBeTrue();
});

test('an edge bot score above the threshold is a human', function() {
    $settings = new Settings();
    $settings->botScoreHeader = 'cf-bot-score';
    $settings->botScoreThreshold = 30;

    $chrome = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.0.0 Safari/537.36';

    expect(bot($settings)->isBot($chrome, HUMAN_HEADERS + ['cf-bot-score' => '80']))->toBeFalse();
});

test('the edge signal is off when no header is configured', function() {
    // A low score present on the request must be ignored while the feature is
    // unconfigured - the default state must never invent a bot.
    $chrome = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.0.0 Safari/537.36';

    expect(bot()->isBot($chrome, HUMAN_HEADERS + ['cf-bot-score' => '1']))->toBeFalse();
});

test('an edge-detected crawler is named for the report', function() {
    $settings = new Settings();
    $settings->botScoreHeader = 'cf-bot-score';
    $settings->botScoreThreshold = 30;

    $chrome = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.0.0 Safari/537.36';

    // A UA-matched crawler keeps its name; the edge label is only the fallback.
    expect(bot($settings)->crawlerName($chrome, ['cf-bot-score' => '5']))->toBe('Data-centre (edge)')
        ->and(bot($settings)->crawlerName('Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)'))->toContain('ooglebot');
});
