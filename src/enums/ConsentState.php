<?php

namespace coyshdigital\craftanalytics\enums;

/**
 * What a visitor has told us.
 *
 * Only `Granted` unlocks Tier-2. Everything else — including "we have not
 * asked yet" — means anonymous tracking, because consent is an affirmative
 * act and the absence of a "no" is not a "yes".
 */
enum ConsentState: string
{
    /** Never asked, or asked and not answered. */
    case Unknown = 'unknown';

    /** An affirmative, recorded act. The only state that unlocks Tier-2. */
    case Granted = 'granted';

    /** Actively refused, or withdrawn after having been given. */
    case Denied = 'denied';

    public function isGranted(): bool
    {
        return $this === self::Granted;
    }

    public function label(): string
    {
        return match ($this) {
            self::Unknown => 'Not given',
            self::Granted => 'Granted',
            self::Denied => 'Denied',
        };
    }
}
