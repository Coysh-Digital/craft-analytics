<?php

/**
 * The control panel has no build step, so vendored front-end libraries are
 * committed as pre-minified blobs. Nothing about a blob tells you what it is,
 * which version it is, or whether anyone has edited it since.
 *
 * PROVENANCE.md is the answer to all three, and this test is what stops it
 * drifting from the files it describes. Swap a vendored file without updating
 * the table and this goes red — which is the only reason the table can be
 * trusted at all.
 */

$vendorDir = dirname(__DIR__, 2) . '/src/resources/cp/js/vendor';

/**
 * The rows of PROVENANCE.md's file table that carry a checksum.
 *
 * Parsed out of the Markdown rather than duplicated here: a copy in this file
 * would be a second thing to keep in step, and the whole point is to have one.
 *
 * Rows whose checksum column is "—" (the licence texts) are skipped, as is the
 * separate tarball table, which has no file of its own in this directory.
 *
 * @return array<string,array{version: string, sha256: string}>
 */
function provenanceRows(string $vendorDir): array
{
    $markdown = (string)file_get_contents($vendorDir . '/PROVENANCE.md');
    $rows = [];

    // | `file` | package | version | upstream path | `sha256` | added |
    preg_match_all(
        '/^\|\s*`([^`]+)`\s*\|([^|]*)\|([^|]*)\|[^|]*\|\s*`([0-9a-f]{64})`\s*\|/m',
        $markdown,
        $matches,
        PREG_SET_ORDER,
    );

    foreach ($matches as $match) {
        $rows[$match[1]] = ['version' => trim($match[3]), 'sha256' => $match[4]];
    }

    return $rows;
}

/** @return array<string,string> file name => expected sha256 */
function provenanceChecksums(string $vendorDir): array
{
    return array_map(static fn(array $row): string => $row['sha256'], provenanceRows($vendorDir));
}

test('the provenance table describes every vendored library file', function() use ($vendorDir) {
    $recorded = array_keys(provenanceChecksums($vendorDir));

    // Licence texts and the record itself are listed without a checksum;
    // everything the browser actually executes must have one.
    $shipped = array_values(array_filter(
        array_map('basename', (array)glob($vendorDir . '/*.{js,css}', GLOB_BRACE)),
        static fn(string $file): bool => !str_starts_with($file, 'LICENSE'),
    ));

    sort($recorded);
    sort($shipped);

    expect($recorded)->toBe($shipped);
});

test('every vendored file still hashes to what was recorded', function() use ($vendorDir) {
    $mismatched = [];

    foreach (provenanceChecksums($vendorDir) as $file => $expected) {
        $path = $vendorDir . '/' . $file;

        if (!is_file($path)) {
            $mismatched[] = "$file is missing";
            continue;
        }

        $actual = hash_file('sha256', $path);

        if ($actual !== $expected) {
            $mismatched[] = "$file is $actual, PROVENANCE.md says $expected";
        }
    }

    expect($mismatched)->toBe([], implode('; ', $mismatched)
        . ' - if this was a deliberate upgrade, update PROVENANCE.md and THIRD-PARTY-LICENSES.md too');
});

test('the provenance table pins a version for every vendored file', function() use ($vendorDir) {
    foreach (provenanceRows($vendorDir) as $file => $row) {
        // "version not recorded anywhere" is how this directory looked before,
        // and it is the state the table exists to stop recurring.
        expect($row['version'])->toMatch('/^\d+\.\d+\.\d+$/', "no version pinned for $file");
    }
});

test('every vendored library keeps its licence text beside it', function() use ($vendorDir) {
    // The project's own rule: verbatim-copied code must preserve its
    // attribution in-tree, not merely in a table somewhere else.
    expect($vendorDir . '/LICENSE.chartjs.txt')->toBeReadableFile()
        ->and($vendorDir . '/LICENSE.jsvectormap.txt')->toBeReadableFile();
});

test('the Chart.js bundle keeps its copyright banner', function() use ($vendorDir) {
    $head = (string)file_get_contents($vendorDir . '/chart.umd.js', false, null, 0, 200);

    expect($head)->toContain('Chart.js v')
        ->and($head)->toContain('MIT License');
});
