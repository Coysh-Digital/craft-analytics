<?php

namespace coyshdigital\craftanalytics\write;

use coyshdigital\craftanalytics\ingest\Hit;
use Craft;
use yii\base\Component;

/**
 * Writes the hit synchronously, after the response has been flushed.
 *
 * No spool, no worker, nothing to schedule — at the cost of a transaction per
 * pageview, and of session updates racing between concurrent workers. Fine
 * for a brochure site; the CP recommends against it above a traffic
 * threshold (phase 5).
 */
class DirectWriter extends Component implements WriterInterface
{
    public ?HitApplier $applier = null;

    public function write(Hit $hit): void
    {
        try {
            ($this->applier ??= new HitApplier())->apply($hit);
        } catch (\Throwable $e) {
            Craft::warning('Failed to write analytics hit: ' . $e->getMessage(), __METHOD__);
        }
    }
}
