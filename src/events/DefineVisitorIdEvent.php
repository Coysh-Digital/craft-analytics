<?php

namespace coyshdigital\craftanalytics\events;

use craft\web\Request;
use yii\base\Event;

/**
 * Lets a site use its own identifier for a consented visitor.
 *
 * Fires only once the visitor has already got past every gate there is: Pro,
 * `enableConsent`, no browser privacy signal, and an affirmative grant. It
 * decides *what the id is*, never whether there is one — a handler cannot
 * conjure a consented visitor out of somebody who refused.
 *
 * Replacing the id has a consequence worth being deliberate about. The one we
 * issue is 16 random bytes that mean nothing outside this plugin; a customer
 * number means something everywhere else in the business, and joining the two
 * is the whole point of supplying it. That makes the journeys table directly
 * identifiable rather than pseudonymous, which the Privacy screen says out
 * loud as soon as a handler is attached.
 */
class DefineVisitorIdEvent extends Event
{
    /**
     * @param string|null $visitorId the id resolved from the consent cookie,
     *                               and the one to replace
     * @param array<string,mixed> $config
     */
    public function __construct(
        public readonly Request $request,
        public readonly int $siteId,
        public ?string $visitorId,
        array $config = [],
    ) {
        parent::__construct($config);
    }
}
