<?php

namespace coyshdigital\craftanalytics\events;

use coyshdigital\craftanalytics\models\SegmentDefinition;
use yii\base\Event;

/**
 * Lets a site declare the segments it will report.
 *
 * Fires once per request, the first time anything asks the registry what it
 * knows. Nothing that is not declared here is ever recorded — from either the
 * server or the beacon.
 */
class RegisterSegmentsEvent extends Event
{
    /** @var SegmentDefinition[] */
    public array $segments = [];
}
