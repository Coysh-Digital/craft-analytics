<?php

namespace coyshdigital\craftanalytics\events;

use coyshdigital\craftanalytics\ingest\Hit;
use craft\events\CancelableEvent;

/**
 * Raised before a hit is handed to the write path. Set `isValid = false` to
 * drop it.
 */
class TrackEvent extends CancelableEvent
{
    /**
     * @param array<string,mixed> $config
     */
    public function __construct(
        public Hit $hit,
        array $config = [],
    ) {
        parent::__construct($config);
    }
}
