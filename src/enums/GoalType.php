<?php

namespace coyshdigital\craftanalytics\enums;

/**
 * What counts as a conversion.
 *
 * Every one of these is decidable from the session hot layer when a session
 * closes — no raw hit rows, no query over history (C6).
 */
enum GoalType: string
{
    /** They reached a page whose path matches a pattern. */
    case Url = 'url';

    /** A named event fired. */
    case Event = 'event';

    /** They viewed a specific entry. */
    case Element = 'element';

    /** The session lasted at least N seconds. */
    case Duration = 'duration';

    /** They read at least N% down a page. */
    case Scroll = 'scroll';

    public function label(): string
    {
        return match ($this) {
            self::Url => 'Page visit',
            self::Event => 'Event',
            self::Element => 'Entry visit',
            self::Duration => 'Session duration',
            self::Scroll => 'Scroll depth',
        };
    }

    /** What the goal's `target` means for this type, for the CP form. */
    public function targetHint(): string
    {
        return match ($this) {
            self::Url => 'A path, with * as a wildcard — e.g. /thank-you or /checkout/*',
            self::Event => 'The event name passed to craftAnalytics.event()',
            self::Element => 'An entry ID',
            self::Duration => 'Seconds',
            self::Scroll => 'A percentage: 25, 50, 75 or 100',
        };
    }
}
