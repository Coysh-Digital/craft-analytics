<?php

namespace coyshdigital\craftanalytics\gql;

use craft\gql\GqlEntityRegistry;
use GraphQL\Type\Definition\ObjectType;
use GraphQL\Type\Definition\Type;

/**
 * Traffic totals, as GraphQL sees them.
 */
class TotalsType
{
    public const NAME = 'CraftAnalyticsTotals';

    public static function getType(): ObjectType
    {
        // Registered once per request: GraphQL requires one instance per named
        // type, and building a second is a hard error rather than a duplicate.
        $type = GqlEntityRegistry::getEntity(self::NAME);

        if ($type instanceof ObjectType) {
            return $type;
        }

        /** @var ObjectType */
        return GqlEntityRegistry::createEntity(self::NAME, new ObjectType([
            'name' => self::NAME,
            'description' => 'Traffic totals for a site and period.',
            'fields' => [
                'views' => [
                    'type' => Type::int(),
                    'description' => 'Pageviews.',
                ],
                'uniques' => [
                    'type' => Type::int(),
                    'description' =>
                        'Unique visitors, on a daily-unique basis: someone visiting on three days counts three ' .
                        'times. That is a deliberate privacy property - the link between a visitor’s days is ' .
                        'destroyed when the hashing salt rotates. The figure is estimated, within about 1.6%.',
                ],
                'sessions' => [
                    'type' => Type::int(),
                    'description' => 'Visits.',
                ],
                'bounceRate' => [
                    'type' => Type::float(),
                    'description' => 'Percentage of sessions that were a single pageview.',
                ],
                'avgDurationMs' => [
                    'type' => Type::int(),
                    'description' => 'Mean session length, in milliseconds.',
                ],
                'avgViewsPerSession' => [
                    'type' => Type::float(),
                    'description' => 'Mean pages per visit.',
                ],
            ],
        ]));
    }
}
