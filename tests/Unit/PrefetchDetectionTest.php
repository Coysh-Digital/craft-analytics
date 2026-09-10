<?php

use coyshdigital\craftanalytics\ingest\CaptureService;
use coyshdigital\craftanalytics\tests\FakeRequest;

/**
 * A prefetch or prerender is a real browser reading ahead of a click, not a
 * visit: it must be counted neither as a pageview nor as a crawler. If the
 * click follows, the page is served from the browser's prefetch cache and the
 * on-load beacon counts it once. These pin the header detection across the four
 * engines that speak it.
 */

function prefetchCapture(): CaptureService
{
    return new CaptureService();
}

dataset('prefetch headers', [
    'Sec-Purpose prefetch' => [['sec-purpose' => 'prefetch']],
    'Sec-Purpose prefetch;anonymous' => [['sec-purpose' => 'prefetch;anonymous-client-ip']],
    'Sec-Purpose prerender' => [['sec-purpose' => 'prerender']],
    'Purpose prefetch' => [['purpose' => 'prefetch']],
    'X-moz prefetch' => [['x-moz' => 'prefetch']],
    'X-moz prerender' => [['x-moz' => 'prerender']],
    'X-Purpose preview' => [['x-purpose' => 'preview']],
    'X-Purpose prefetch' => [['x-purpose' => 'prefetch']],
]);

test('a speculative fetch is detected as a prefetch', function (array $headers) {
    expect(prefetchCapture()->isPrefetch(FakeRequest::make($headers)))->toBeTrue();
})->with('prefetch headers');

test('an ordinary navigation is not a prefetch', function () {
    $document = FakeRequest::make([
        'sec-fetch-dest' => 'document',
        'sec-purpose' => '',
        'accept-language' => 'en-GB,en;q=0.9',
    ]);
    $bare = FakeRequest::make([]);

    expect(prefetchCapture()->isPrefetch($document))->toBeFalse()
        ->and(prefetchCapture()->isPrefetch($bare))->toBeFalse();
});

test('an unrelated Purpose value is not a prefetch', function () {
    // Only the exact speculative tokens count - a stray Purpose header from
    // some other tool must not silently delete a real navigation.
    expect(prefetchCapture()->isPrefetch(FakeRequest::make(['purpose' => 'subresource'])))->toBeFalse();
});
