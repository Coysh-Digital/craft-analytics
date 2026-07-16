<?php

namespace coyshdigital\craftanalytics\write;

use coyshdigital\craftanalytics\ingest\Hit;

/**
 * How a captured hit leaves the web request.
 *
 * Implementations run on the request thread (after the response is flushed),
 * so `write()` must be cheap and must never throw into the application: a
 * broken analytics write is never worth a broken page.
 */
interface WriterInterface
{
    public function write(Hit $hit): void;
}
