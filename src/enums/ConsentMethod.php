<?php

namespace coyshdigital\craftanalytics\enums;

/**
 * How a consent signal reached us.
 *
 * Recorded with every consent decision: "they consented" is not evidence,
 * "they consented via the Klaro banner at 14:32 on 16 July under policy
 * version 3" is.
 */
enum ConsentMethod: string
{
    /** window.craftAnalytics.consent() called directly by the site. */
    case JsApi = 'js';

    /** One of the shipped CMP adapters relayed its CMP's decision. */
    case CmpAdapter = 'cmp';

    /** A cookie the site's own CMP writes, read server-side. */
    case CmpCookie = 'cookie';

    /** IAB TCF v2.2, purposes 1 and 8. */
    case Tcf = 'tcf';

    /** The site resolved consent itself via the defineConsent event. */
    case ServerEvent = 'server';

    /** Global Privacy Control, or the legacy DNT header. */
    case PrivacySignal = 'signal';

    public function label(): string
    {
        return match ($this) {
            self::JsApi => 'JavaScript API',
            self::CmpAdapter => 'CMP adapter',
            self::CmpCookie => 'CMP cookie',
            self::Tcf => 'IAB TCF v2.2',
            self::ServerEvent => 'Server-side event',
            self::PrivacySignal => 'Browser privacy signal',
        };
    }
}
