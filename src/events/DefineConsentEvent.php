<?php

namespace coyshdigital\craftanalytics\events;

use coyshdigital\craftanalytics\enums\ConsentMethod;
use coyshdigital\craftanalytics\enums\ConsentState;
use yii\base\Event;

/**
 * Lets a site resolve consent from its own session, CRM or CMP state.
 *
 * Fires last, with whatever the plugin worked out from cookies already in
 * `$state`. A handler may change it — the site knows things we don't.
 *
 * One exception it cannot override: a browser privacy signal (GPC). If the
 * visitor's browser has said no, that is not ours or the site's to reverse.
 */
class DefineConsentEvent extends Event
{
    public function __construct(
        public ConsentState $state,
        public ConsentMethod $method,
        /** The site the decision applies to. */
        public readonly int $siteId,
        array $config = [],
    ) {
        parent::__construct($config);
    }
}
