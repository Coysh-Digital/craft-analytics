<?php

namespace coyshdigital\craftanalytics\gql;

use craft\gql\GqlEntityRegistry;
use GraphQL\Type\Definition\ObjectType;
use GraphQL\Type\Definition\Type;

/**
 * One page's numbers, as GraphQL sees them.
 */
class PageType
{
    public const NAME = 'CraftAnalyticsPage';

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
            'description' => 'A page and its traffic.',
            'fields' => [
                'path' => [
                    'type' => Type::string(),
                    'description' =>
                        'The path, with campaign parameters stripped. “__other__” is where values beyond the ' .
                        'daily cardinality cap are folded; no views are lost to it.',
                ],
                'elementId' => [
                    'type' => Type::int(),
                    'description' => 'The entry this path resolved to, if it was one.',
                ],
                'views' => ['type' => Type::int()],
                'entrances' => [
                    'type' => Type::int(),
                    'description' => 'Sessions that began here.',
                ],
                'exits' => [
                    'type' => Type::int(),
                    'description' => 'Sessions that ended here.',
                ],
                'bounces' => [
                    'type' => Type::int(),
                    'description' => 'Sessions that began and ended here, having seen nothing else.',
                ],
                'bounceRate' => [
                    'type' => Type::float(),
                    'description' => 'Bounces as a percentage of entrances.',
                ],
                'avgDwellMs' => [
                    'type' => Type::int(),
                    'description' =>
                        'Mean time on page, in milliseconds. Always 0 in server-only tracking mode, which has no ' .
                        'way of knowing when somebody left.',
                ],
            ],
        ]));
    }
}
