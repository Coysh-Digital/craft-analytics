<?php

namespace coyshdigital\craftanalytics\write;

use coyshdigital\craftanalytics\ingest\Hit;
use yii\base\BaseObject;
use yii\queue\JobInterface;

/**
 * Applies one spooled-to-queue hit. Runs on the dedicated analytics queue —
 * never Craft's, which is shared with work that matters more than a
 * pageview.
 */
class TrackJob extends BaseObject implements JobInterface
{
    /** The encoded hit; kept as a string so the payload stays small. */
    public string $hit = '';

    public function execute($queue): void
    {
        $hit = Hit::decode($this->hit);

        if ($hit === null) {
            return;
        }

        (new HitApplier())->apply($hit);
    }
}
