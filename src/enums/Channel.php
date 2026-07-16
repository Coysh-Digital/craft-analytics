<?php

namespace coyshdigital\craftanalytics\enums;

/**
 * How a session arrived.
 *
 * Stored as a small int on the sources rollup rather than as a dimension:
 * the set is closed and known, so it needs no lookup table.
 */
enum Channel: int
{
    case Direct = 0;
    case Search = 1;
    case Social = 2;
    case Referral = 3;
    case Campaign = 4;
    case Internal = 5;

    public function label(): string
    {
        return match ($this) {
            self::Direct => 'Direct',
            self::Search => 'Search',
            self::Social => 'Social',
            self::Referral => 'Referral',
            self::Campaign => 'Campaign',
            self::Internal => 'Internal',
        };
    }
}
