<?php

use coyshdigital\craftanalytics\services\BotFilter;

const HUMAN_HEADERS = ['accept-language' => 'en-GB,en;q=0.9'];

/**
 * Real UA strings. Precision matters as much as recall here: wrongly
 * classifying a human as a bot silently deletes traffic from the numbers,
 * which is worse than counting the odd crawler.
 */
dataset('humans', [
    'Chrome/Mac' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.0.0 Safari/537.36',
    'Safari/iPhone' => 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_5 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.5 Mobile/15E148 Safari/604.1',
    'Firefox/Windows' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:127.0) Gecko/20100101 Firefox/127.0',
    'Edge/Windows' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.0.0 Safari/537.36 Edg/126.0.0.0',
    'Chrome/Android' => 'Mozilla/5.0 (Linux; Android 14; Pixel 8) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.0.0 Mobile Safari/537.36',
    'Samsung Internet' => 'Mozilla/5.0 (Linux; Android 13; SAMSUNG SM-S918B) AppleWebKit/537.36 (KHTML, like Gecko) SamsungBrowser/23.0 Chrome/115.0.0.0 Mobile Safari/537.36',
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
    expect((new BotFilter())->isBot($ua, HUMAN_HEADERS))->toBeFalse();
})->with('humans');

test('crawlers are filtered', function(string $ua) {
    expect((new BotFilter())->isBot($ua, HUMAN_HEADERS))->toBeTrue();
})->with('bots');

test('headless and automation drivers are filtered', function(string $ua) {
    expect((new BotFilter())->isBot($ua, HUMAN_HEADERS))->toBeTrue();
})->with('automation');

test('an empty user agent is filtered', function() {
    expect((new BotFilter())->isBot('', HUMAN_HEADERS))->toBeTrue();
});

test('a missing Accept-Language is filtered', function() {
    $chrome = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.0.0 Safari/537.36';

    expect((new BotFilter())->isBot($chrome, []))->toBeTrue()
        ->and((new BotFilter())->isBot($chrome, ['accept-language' => '']))->toBeTrue();
});

test('a client reporting navigator.webdriver is filtered regardless of UA', function() {
    $chrome = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.0.0 Safari/537.36';

    expect((new BotFilter())->isBot($chrome, HUMAN_HEADERS, clientWebdriver: true))->toBeTrue();
});
