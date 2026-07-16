<?php

namespace coyshdigital\craftanalytics\gql;

use coyshdigital\craftanalytics\models\DateRange;
use coyshdigital\craftanalytics\Plugin;
use Craft;
use craft\gql\base\Query;
use GraphQL\Type\Definition\Type;

/**
 * The GraphQL queries.
 *
 * Behind a schema scope, so a public token cannot read a site's traffic
 * unless somebody deliberately granted it. What can be read is aggregate
 * either way (C6) - the scope is about not publishing your numbers, not
 * about protecting visitors, who have nothing here to protect.
 */
class AnalyticsQuery extends Query
{
    public const SCOPE = 'craftAnalytics.read';

    /**
     * @return array<string,array<string,mixed>>
     */
    public static function getQueries(bool $checkToken = true): array
    {
        if ($checkToken && !Gql::canQueryAnalytics()) {
            return [];
        }

        return [
            'craftAnalyticsTotals' => [
                'type' => TotalsType::getType(),
                'args' => self::commonArgs(),
                'resolve' => static function($source, array $arguments) {
                    $siteId = self::siteId($arguments);
                    $range = DateRange::fromPreset($arguments['period'] ?? DateRange::PRESET_30_DAYS);

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
                    $range = DateRange::fromPreset($arguments['period'] ?? DateRange::PRESET_30_DAYS);
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
                'description' => 'The site to report on. Defaults to the primary site.',
            ],
            'period' => [
                'name' => 'period',
                'type' => Type::string(),
                'description' => 'One of: today, yesterday, 7d, 30d, 90d, 12mo. Defaults to 30d.',
            ],
        ];
    }

    /**
     * @param array<string,mixed> $arguments
     */
    private static function siteId(array $arguments): int
    {
        return (int)($arguments['siteId'] ?? Craft::$app->getSites()->getPrimarySite()->id);
    }
}
