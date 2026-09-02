<?php

use coyshdigital\craftanalytics\enums\Ga4Dataset;

test('a chosen group expands to its datasets in order', function() {
    expect(Ga4Dataset::forGroups(['pages']))->toBe([Ga4Dataset::Pages, Ga4Dataset::Totals]);
    expect(Ga4Dataset::forGroups(['sources']))->toBe([Ga4Dataset::Sources, Ga4Dataset::Campaigns]);
    expect(Ga4Dataset::forGroups(['devices']))->toBe([Ga4Dataset::Devices, Ga4Dataset::Geo]);
    expect(Ga4Dataset::forGroups(['events']))->toBe([Ga4Dataset::Events]);
});

test('an unknown group contributes nothing', function() {
    expect(Ga4Dataset::forGroups(['nonsense']))->toBe([]);
});

test('campaigns, geo and events are the Pro datasets', function() {
    $pro = array_values(array_filter(Ga4Dataset::cases(), fn(Ga4Dataset $d) => $d->isPro()));

    expect($pro)->toBe([Ga4Dataset::Campaigns, Ga4Dataset::Geo, Ga4Dataset::Events]);
});

test('every dataset leads with the date dimension', function() {
    foreach (Ga4Dataset::cases() as $dataset) {
        expect($dataset->dimensions()[0])->toBe('date');
    }
});

test('the four wizard groups are exactly the datasets grouped up', function() {
    $groups = [];

    foreach (Ga4Dataset::cases() as $dataset) {
        $groups[$dataset->group()] = true;
    }

    expect(array_keys($groups))->toEqualCanonicalizing(Ga4Dataset::groups());
});
