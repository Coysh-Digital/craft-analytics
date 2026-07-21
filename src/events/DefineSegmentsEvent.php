<?php

namespace coyshdigital\craftanalytics\events;

use craft\web\Request;
use yii\base\Event;

/**
 * Lets a site put values on the segments it declared, for this request.
 *
 * Runs on the request thread of a page that has already been sent, so it has
 * the session, the signed-in user and anything else the site knows. Keep it
 * cheap all the same: the worker is held until it returns.
 *
 * Whatever a handler puts in `$segments` is filtered afterwards against the
 * declarations — unknown keys and undeclared values are dropped, silently and
 * on both capture paths.
 */
class DefineSegmentsEvent extends Event
{
    /**
     * @param array<string,string> $segments key => value, mutable
     * @param array<string,mixed> $config
     */
    public function __construct(
        public readonly Request $request,
        public readonly int $siteId,
        public array $segments = [],
        array $config = [],
    ) {
        parent::__construct($config);
    }
}
