<?php

namespace coyshdigital\craftanalytics\helpers;

use Craft;
use craft\base\ElementInterface;
use craft\db\Query;
use craft\db\Table as CraftTable;

/**
 * Control-panel edit links for the elements a report mentions.
 *
 * Craft owns what an element's edit URL looks like, and it is not something
 * you can build from an ID. An entry's is
 * `content/entries/{section}/{id}-{slug}`, which needs the section and the
 * slug; a category's is different again, and both have changed between Craft
 * versions. Guessing `entries/{id}` produced links that 404'd.
 *
 * So this asks the elements. In batch, because a report has a hundred rows and
 * a hundred queries to render a table is not a trade worth making.
 */
class ElementLinks
{
    /**
     * Edit URLs for the given elements, keyed by ID.
     *
     * Elements that no longer exist, or that the user can't edit, are simply
     * absent - the caller renders a plain label instead of a broken link.
     *
     * @param array<int,int|null> $elementIds
     * @return array<int,string>
     */
    public static function editUrls(array $elementIds): array
    {
        $ids = array_values(array_unique(array_filter(
            array_map(static fn($id) => $id === null ? null : (int)$id, $elementIds),
        )));

        if ($ids === []) {
            return [];
        }

        $urls = [];

        // One query per element *type*, rather than one per element. In
        // practice that is one, because everything with a URI on a site
        // request is an entry.
        foreach (self::idsByType($ids) as $class => $classIds) {
            /** @var class-string<ElementInterface> $class */
            if (!class_exists($class) || !is_subclass_of($class, ElementInterface::class)) {
                continue;
            }

            $elements = $class::find()
                ->id($classIds)
                ->status(null)
                ->drafts(false)
                ->revisions(false)
                ->all();

            foreach ($elements as $element) {
                if (!$element instanceof ElementInterface) {
                    continue;
                }

                $url = $element->getCpEditUrl();

                if ($url !== null && $element->id !== null) {
                    $urls[$element->id] = $url;
                }
            }
        }

        return $urls;
    }

    /**
     * @param array<int,int> $ids
     * @return array<string,array<int,int>> element class => ids
     */
    private static function idsByType(array $ids): array
    {
        $rows = (new Query())
            ->select(['id', 'type'])
            ->from([CraftTable::ELEMENTS])
            ->where(['id' => $ids])
            ->andWhere(['dateDeleted' => null])
            ->all();

        $byType = [];

        foreach ($rows as $row) {
            $byType[(string)$row['type']][] = (int)$row['id'];
        }

        return $byType;
    }
}
