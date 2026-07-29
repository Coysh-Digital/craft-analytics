<?php

use coyshdigital\craftanalytics\helpers\Csv;

/**
 * An export is a file an administrator opens in Excel or Sheets, and most of
 * what is in it was written by somebody else: paths, referrer hosts, event
 * names, search terms. The beacon accepts any path starting with `/`, so
 * requesting `/=HYPERLINK(...)` is enough to put a formula in `pages_rollup`
 * and wait for someone to export it.
 */
test('a path that looks like a formula is defused', function() {
    $csv = Csv::encode([['path' => '=HYPERLINK("https://attacker.example","Click")', 'views' => 3]]);

    expect($csv)->toContain("'=HYPERLINK")
        ->and($csv)->not->toMatch('/^=|,=/m');
});

test('every formula lead-in is caught', function(string $value) {
    $csv = Csv::encode([['value' => $value]]);

    expect($csv)->toContain("'" . $value);
})->with(['=1+1', '+1', '-2+3', '@SUM(A1)']);

test('whitespace before the formula does not smuggle it through', function() {
    // A parser looking for the opening character looks past leading
    // whitespace, so the guard has to as well.
    $csv = Csv::encode([['value' => "\t=1+1"]]);

    expect($csv)->toContain("'\t=1+1");
});

test('ordinary values are untouched', function(string $value) {
    $csv = Csv::encode([['value' => $value]]);

    expect($csv)->not->toContain("'" . $value);
})->with(['/pricing', 'www.google.com', 'newsletter signup', 'Direct', '']);

test('numbers stay numbers, including negative ones', function() {
    // These arrive from the stats service as ints and floats, so a negative
    // delta keeps its minus sign rather than becoming text.
    $csv = Csv::encode([['change' => -12.5, 'views' => 40]]);

    expect($csv)->toContain('-12.5')
        ->and($csv)->not->toContain("'-12.5");
});

test('the header row is written from the first row keys', function() {
    $csv = Csv::encode([
        ['path' => '/a', 'views' => 1],
        ['path' => '/b', 'views' => 2],
    ]);

    expect(explode("\n", trim($csv)))->toBe(['path,views', '/a,1', '/b,2']);
});

test('no rows means no header and no file content', function() {
    expect(Csv::encode([]))->toBe('');
});
