<?php

namespace coyshdigital\craftanalytics\gql;

use craft\helpers\Gql as GqlHelper;

/**
 * Schema access for the analytics queries.
 */
class Gql
{
    /**
     * Whether the current schema is allowed to read analytics.
     *
     * Denied unless a schema explicitly grants it. The numbers are aggregate
     * and expose nobody, but they are still the site's business rather than
     * the internet's, so the default is no.
     */
    public static function canQueryAnalytics(): bool
    {
        return GqlHelper::canSchema(AnalyticsQuery::SCOPE);
    }
}
