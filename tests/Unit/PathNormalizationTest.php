<?php

use coyshdigital\craftanalytics\ingest\CaptureService;
use coyshdigital\craftanalytics\models\Settings;

/**
 * A page must be recorded under one path, not fragmented into a row per
 * tracking parameter. Campaign tags, ad-network artefacts and Craft's preview
 * token are always stripped; the query is otherwise kept, in a stable order,
 * unless stripQueryString drops it entirely.
 */
function normalize(string $pathInfo, string $query, ?Settings $settings = null): string
{
    $capture = new CaptureService();
    $capture->settings = $settings ?? new Settings();

    return $capture->normalizePath($pathInfo, $query);
}

test('an empty query yields the clean path', function() {
    expect(normalize('/membership/join-us-today', ''))
        ->toBe('/membership/join-us-today');
});

test('campaign tags and click IDs are always stripped', function() {
    expect(normalize('/page', 'utm_source=newsletter&utm_medium=email'))->toBe('/page');
    expect(normalize('/page', 'gclid=abc123'))->toBe('/page');
    expect(normalize('/page', 'fbclid=xyz'))->toBe('/page');
});

test('newer ad-network and analytics parameters are stripped', function(string $query) {
    expect(normalize('/', $query))->toBe('/');
})->with([
    'gad_source=1',
    'gad_campaignid=23810449326',
    'gclsrc=aw.ds',
    'srsltid=abc',
    '_gl=1*mnrryv*_gcl_au*MTA2',
    '_ga=GA1.1.123',
]);

test("Craft's preview token is stripped", function() {
    expect(normalize('/news-and-views', 'token=me5KGW2gQ3OVnIekRxzSduyJ'))
        ->toBe('/news-and-views');
});

test('a genuine page parameter is kept by default', function() {
    expect(normalize('/search/results', 'q=hello'))
        ->toBe('/search/results?q=hello');
});

test('remaining parameters are sorted so one page stays one row', function() {
    expect(normalize('/list', 'b=2&a=1'))->toBe('/list?a=1&b=2');
});

test('a site-specific parameter can be named in excludeQueryParams', function() {
    $settings = new Settings();
    $settings->excludeQueryParams = ['ad', 'cid'];

    expect(normalize('/membership/join-us-today', 'ad=120246329263250047', $settings))
        ->toBe('/membership/join-us-today');
    expect(normalize('/membership/join-us-today', 'cid=ce084f80-eb7c', $settings))
        ->toBe('/membership/join-us-today');
});

test('entity-encoded separators are decoded so the parameter beneath is stripped', function() {
    // A link written with `&amp;` instead of `&`, sometimes escaped more than
    // once, would otherwise reach us as a parameter named `amp;utm_source`
    // that no strip list matches - fragmenting the path.
    expect(normalize('/page', 'utm_source=x&amp;utm_medium=email'))->toBe('/page');
    expect(normalize('/search/results', 'amp;amp;utm_source=old&q=hello'))
        ->toBe('/search/results?q=hello');
});

test('stripQueryString drops the whole query, even a would-be-kept parameter', function() {
    $settings = new Settings();
    $settings->stripQueryString = true;

    expect(normalize('/search/results', 'q=hello&page=2', $settings))
        ->toBe('/search/results');
    expect(normalize('/membership/join-us-today', 'ad=120246329263250047', $settings))
        ->toBe('/membership/join-us-today');
});
