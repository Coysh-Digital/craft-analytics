<?php

namespace coyshdigital\craftanalytics\gql;

use coyshdigital\craftanalytics\models\DateRange;
use coyshdigital\craftanalytics\Plugin;
use Craft;
use craft\gql\base\Query;
use craft\helpers\Gql as GqlHelper;
use craft\models\Site;
use GraphQL\Error\UserError;
use GraphQL\Type\Definition\Type;

/**
 * The GraphQL queries.
 *
 * Behind two scopes, and it needs both: `craftAnalytics.read` to read
 * analytics at all, and the ordinary `sites.{uid}` scope for the site being
 * asked about. The second is the one that is easy to forget, and forgetting it
 * means a token scoped to one site reads every site on the install by passing
 * a `siteId` - see siteId() below.
 *
 * What can be read is aggregate either way (C6) - the scope is about not
 * publishing your numbers, not about protecting visitors, who have nothing
 * here to protect.
 */
class AnalyticsQuery extends Query
{
    public const SCOPE = 'craftAnalytics.read';

    /**
     * The API is a Pro feature, so Lite registers no queries at all.
     *
     * Checked here as well as at the point of registration, because returning
     * an empty list is what makes the schema itself honest: a query that is
     * never declared cannot be called, cannot be introspected, and cannot be
     * left reachable by some future caller that forgets the edition.
     *
     * `$isPro` is injectable for the same reason it is on the rollup writers -
     * `Plugin::getInstance()` is null in the test harness.
     *
     * @return array<string,array<string,mixed>>
     */
    public static function getQueries(bool $checkToken = true, ?bool $isPro = null): array
    {
        if (!($isPro ?? Plugin::getInstance()->is(Plugin::EDITION_PRO))) {
            return [];
        }

        if ($checkToken && !Gql::canQueryAnalytics()) {
            return [];
        }

        return [
            'craftAnalyticsTotals' => [
                'type' => TotalsType::getType(),
                'args' => self::commonArgs(),
                'resolve' => static function($source, array $arguments) {
                    $siteId = self::siteId($arguments);
                    $range = DateRange::fromParam($arguments['period'] ?? DateRange::PRESET_30_DAYS);

                    return Plugin::getInstance()->getStats()->totals($siteId, $range);
                },
                'description' => 'Traffic totals for a period.',
            ],
            'craftAnalyticsTopPages' => [
                'type' => Type::listOf(PageType::getType()),
                'args' => self::commonArgs() + [
                    'limit' => [
                        'name' => 'limit',
                        'type' => Type::int(),
                        'description' => 'How many pages to return. Defaults to 10.',
                    ],
                ],
                'resolve' => static function($source, array $arguments) {
                    $siteId = self::siteId($arguments);
                    $range = DateRange::fromParam($arguments['period'] ?? DateRange::PRESET_30_DAYS);
                    $limit = min((int)($arguments['limit'] ?? 10), 200);

                    return Plugin::getInstance()->getStats()->topPages($siteId, $range, $limit);
                },
                'description' => 'The most-viewed pages of a period.',
            ],
        ];
    }

    /**
     * @return array<string,array<string,mixed>>
     */
    private static function commonArgs(): array
    {
        return [
            'siteId' => [
                'name' => 'siteId',
                'type' => Type::int(),
                'description' => 'The site to report on. Must be a site this schema can read. '
                    . 'Defaults to the primary site, or the first readable one.',
            ],
            'period' => [
                'name' => 'period',
                'type' => Type::string(),
                'description' => 'One of: today, yesterday, 7d, 30d, 90d, 12mo. '
                    . 'An absolute window may be given instead, as two dates '
                    . '"YYYY-MM-DD:YYYY-MM-DD", spanning at most 400 days. '
                    . 'Anything unrecognised is read as 30d.',
            ],
        ];
    }

    /**
     * The site to report on, once the schema has been asked whether it may.
     *
     * `craftAnalytics.read` says a token may read analytics; it does not say
     * *whose*. Without this, one grant let a token read every site on the
     * install by passing a `siteId`, straight past the per-site scoping Craft
     * applies to everything else - and past the equivalent check the CP does
     * in BaseCpController::allowedSites().
     *
     * @param array<string,mixed> $arguments
     */
    private static function siteId(array $arguments): int
    {
        $sites = Craft::$app->getSites();

        if (isset($arguments['siteId'])) {
            $site = $sites->getSiteById((int)$arguments['siteId']);

            // Unreadable and non-existent get the same answer, so the schema
            // can't be used to enumerate which site IDs exist.
            if ($site === null || !self::canReadSite($site)) {
                throw new UserError('Analytics for that site are not in this schema.');
            }

            return (int)$site->id;
        }

        // No site asked for: the primary one, unless the schema can't read it,
        // in which case the first one it can. A token scoped to a single site
        // shouldn't have to name it.
        $primary = $sites->getPrimarySite();

        if (self::canReadSite($primary)) {
            return (int)$primary->id;
        }

        foreach ($sites->getAllSites() as $site) {
            if (self::canReadSite($site)) {
                return (int)$site->id;
            }
        }

        throw new UserError('This schema cannot read analytics for any site.');
    }

    private static function canReadSite(Site $site): bool
    {
        return GqlHelper::canSchema('sites.' . $site->uid);
    }
}
