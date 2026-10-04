<?php

namespace coyshdigital\craftanalytics\helpers;

/**
 * Bounds on how many rows a caller may ask a report for.
 *
 * The GraphQL `limit` argument and the Twig methods' `$limit` are chosen by
 * whoever writes the query, and on a public schema that is anyone. `min()`
 * alone looked like a cap but was not one: a negative limit went through it
 * untouched, and Yii emits no `LIMIT` clause at all for a value that is not a
 * run of digits, so `limit: -1` returned every path row the site had,
 * uncached. Clamped at both ends here, once, so every entry point agrees.
 */
final class Limits
{
    /** The most rows any report query hands out, however it is asked. */
    public const MAX_ROWS = 200;

    public static function rows(int $requested, int $max = self::MAX_ROWS): int
    {
        return max(1, min($requested, $max));
    }
}
