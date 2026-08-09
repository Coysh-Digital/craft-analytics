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

/**
 * A trailing backslash used to swallow the rest of the file.
 *
 * fputcsv()'s default escape character is a backslash. Given a value ending
 * in one it encloses the field without doubling it, so `/downloads\` was
 * written as "/downloads\". A strict RFC 4180 reader copes, because a
 * backslash means nothing to it - but a reader that honours backslash escapes
 * takes that closing quote as escaped and keeps consuming, collapsing every
 * remaining row into one cell. PHP's own fgetcsv() is in the second group by
 * default, so the export could not be read back by the language that wrote it.
 *
 * Reachable the same way the formula case is: the beacon accepts any path
 * starting with a slash and rejects only newlines and `://`.
 */
test('a path ending in a backslash does not swallow the rows after it', function() {
    $csv = Csv::encode([
        ['path' => '/downloads\\', 'views' => 7],
        ['path' => '/pricing', 'views' => 9],
    ]);

    // Read the way PHP reads by default, which is the case that broke.
    $rows = readCsv($csv, '\\');

    expect($rows)->toHaveCount(3)
        ->and($rows[1][0])->toBe('/downloads\\')
        ->and($rows[1][1])->toBe('7')
        // The row after it survived rather than being eaten by the one before.
        ->and($rows[2][0])->toBe('/pricing')
        ->and($rows[2][1])->toBe('9');
});

test('a spreadsheet reads the same file the same way', function() {
    $csv = Csv::encode([
        ['path' => '/downloads\\', 'views' => 7],
        ['path' => '/pricing', 'views' => 9],
    ]);

    // No escape character: RFC 4180, which is what Excel and Sheets do.
    $rows = readCsv($csv);

    expect($rows)->toHaveCount(3)
        ->and($rows[1][0])->toBe('/downloads\\')
        ->and($rows[2][0])->toBe('/pricing');
});

test('a quote inside a value is still escaped the way a spreadsheet expects', function() {
    $csv = Csv::encode([['path' => '/say/"hello"', 'views' => 1]]);
    $rows = readCsv($csv);

    expect($rows[1][0])->toBe('/say/"hello"')
        ->and($rows[1][1])->toBe('1');
});

/**
 * Reads a CSV back, with the escape character named rather than defaulted -
 * PHP is in the middle of changing that default, and which one is in force is
 * the entire subject of the tests above.
 *
 * @return array<int,array<int,string|null>>
 */
function readCsv(string $csv, string $escape = ''): array
{
    return array_map(
        static fn(string $line): array => str_getcsv($line, ',', '"', $escape),
        array_values(array_filter(explode("\n", trim($csv)))),
    );
}
