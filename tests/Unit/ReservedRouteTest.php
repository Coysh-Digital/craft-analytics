<?php

use coyshdigital\craftanalytics\ingest\CaptureService;
use coyshdigital\craftanalytics\tests\FakeRequest;

/**
 * Craft/Blitz dynamic-include fragments render the uncached parts of a cached
 * page from their own `/_dynamic_include_<n>` routes. Each is a GET returning
 * 200 text/html, so without these two gates a global header or footer becomes
 * the "most popular page" and the flood of unique fragment URLs buries the real
 * long tail in `__other__`. These pin both halves of the fix.
 */

function capture(): CaptureService
{
    return new CaptureService();
}

test('a dynamic-include route is a reserved route', function () {
    expect(capture()->isReservedRoute('/_dynamic_include_123'))->toBeTrue();
});

test('the fragment entryUri query does not hide the reserved route', function () {
    expect(capture()->isReservedRoute('/_dynamic_include_457251795?entryUri=press-centre'))->toBeTrue();
});

test('a real page is not a reserved route', function () {
    $capture = capture();

    expect($capture->isReservedRoute('/press-centre'))->toBeFalse()
        // The prefix is anchored to the start: a real page that merely contains
        // the fragment segment deeper in its path is still a page.
        ->and($capture->isReservedRoute('/news/_dynamic_include_1'))->toBeFalse()
        ->and($capture->isReservedRoute('/'))->toBeFalse();
});

test('an explicit non-document fetch destination is a sub-request', function () {
    // What Blitz/Sprig/htmx and hand-rolled fetch() send when pulling in part
    // of a page.
    $request = FakeRequest::make(['sec-fetch-dest' => 'empty']);

    expect(capture()->isNonDocumentRequest($request))->toBeTrue();
});

test('a top-level document navigation is not a sub-request', function () {
    $document = FakeRequest::make(['sec-fetch-dest' => 'document']);
    $iframe = FakeRequest::make(['sec-fetch-dest' => 'iframe']);

    expect(capture()->isNonDocumentRequest($document))->toBeFalse()
        ->and(capture()->isNonDocumentRequest($iframe))->toBeFalse();
});

test('a missing Sec-Fetch-Dest fails open so old browsers still count', function () {
    // The header is absent on old browsers, some bots and edge-side includes;
    // its absence must never cost a real navigation a count.
    $request = FakeRequest::make([]);

    expect(capture()->isNonDocumentRequest($request))->toBeFalse();
});

test('the legacy X-Requested-With header marks an AJAX sub-request', function () {
    $request = FakeRequest::make(['X-Requested-With' => 'XMLHttpRequest']);

    expect(capture()->isNonDocumentRequest($request))->toBeTrue();
});
