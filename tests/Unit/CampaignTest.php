<?php

use coyshdigital\craftanalytics\enums\AttributionModel;
use coyshdigital\craftanalytics\models\Campaign;

test('a campaign is read from utm parameters', function() {
    $campaign = Campaign::fromQueryString('utm_source=newsletter&utm_medium=email&utm_campaign=spring');

    expect($campaign)->not->toBeNull()
        ->and($campaign->source)->toBe('newsletter')
        ->and($campaign->medium)->toBe('email')
        ->and($campaign->campaign)->toBe('spring');
});

test('a source alone is a campaign', function() {
    // A link tagged only ?utm_source=newsletter is perfectly ordinary, and
    // refusing it for want of a medium would lose real data.
    $campaign = Campaign::fromQueryString('utm_source=newsletter');

    expect($campaign?->source)->toBe('newsletter')
        ->and($campaign->medium)->toBe('');
});

test('no source means no campaign', function(string $query) {
    expect(Campaign::fromQueryString($query))->toBeNull();
})->with([
    'empty' => '',
    'no utm at all' => 'page=2&sort=name',
    'medium without source' => 'utm_medium=email&utm_campaign=spring',
    'blank source' => 'utm_source=&utm_medium=email',
]);

test('campaign values are case-folded so one campaign stays one campaign', function() {
    // "Newsletter" and "newsletter" are the same thing; treating them as two
    // is how an attribution report turns to mush.
    $a = Campaign::fromQueryString('utm_source=Newsletter&utm_medium=EMAIL');
    $b = Campaign::fromQueryString('utm_source=newsletter&utm_medium=email');

    expect($a?->key())->toBe($b?->key());
});

test('campaign values are trimmed and stripped of control characters', function() {
    $campaign = Campaign::fromQueryString('utm_source=' . urlencode("  news\nletter  "));

    expect($campaign?->source)->toBe('newsletter');
});

test('an absurdly long value is truncated rather than stored', function() {
    $campaign = Campaign::fromQueryString('utm_source=' . str_repeat('a', 500));

    expect(strlen((string)$campaign?->source))->toBe(200);
});

test('a campaign round-trips through the spool', function() {
    $campaign = new Campaign('newsletter', 'email', 'spring', 'shoes', 'variant-b');
    $restored = Campaign::fromArray($campaign->toArray());

    expect($restored?->key())->toBe($campaign->key());
});

test('last-click gives the last touch everything', function() {
    expect(AttributionModel::LastClick->weights(3))->toBe([0.0, 0.0, 1.0]);
});

test('first-click gives the first touch everything', function() {
    expect(AttributionModel::FirstClick->weights(3))->toBe([1.0, 0.0, 0.0]);
});

test('linear splits credit evenly', function() {
    expect(AttributionModel::Linear->weights(4))->toBe([0.25, 0.25, 0.25, 0.25]);
});

test('every model agrees when there is only one touch', function(AttributionModel $model) {
    // Worth knowing before anyone spends an afternoon choosing a model: for
    // the overwhelmingly common single-touch session, they are identical.
    expect($model->weights(1))->toBe([1.0]);
})->with([AttributionModel::LastClick, AttributionModel::FirstClick, AttributionModel::Linear]);

test('a session is never credited as more than one session', function(AttributionModel $model, int $touches) {
    // The arithmetic that stops attribution reports claiming more traffic
    // than the site actually had.
    expect(array_sum($model->weights($touches)))->toEqualWithDelta(1.0, 0.0001);
})->with([AttributionModel::LastClick, AttributionModel::FirstClick, AttributionModel::Linear])
  ->with([1, 2, 3, 7]);

test('no touches means no weights', function(AttributionModel $model) {
    expect($model->weights(0))->toBe([]);
})->with([AttributionModel::LastClick, AttributionModel::FirstClick, AttributionModel::Linear]);

test('campaign and click-id parameters are the ones stripped from paths', function() {
    // They describe how somebody arrived, not which page they arrived at.
    // Leaving them on the path splits one page into a row per campaign and
    // inflates path cardinality for data recorded elsewhere (C2).
    expect(Campaign::PARAMS)
        ->toContain('utm_source')
        ->toContain('utm_content')
        ->toContain('gclid')
        ->toContain('fbclid')
        ->toContain('msclkid');
});

test('a click id alone is not a campaign', function() {
    // gclid identifies an ad click, not a source. It is stripped from the
    // path, but inventing a campaign from it would be making data up.
    expect(Campaign::fromQueryString('gclid=abc123'))->toBeNull();
});
