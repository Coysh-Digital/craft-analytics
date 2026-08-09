<?php

namespace coyshdigital\craftanalytics\helpers;

use coyshdigital\craftanalytics\Plugin;
use Craft;
use craft\models\Site;

/**
 * Which sites a user may see analytics for.
 *
 * Extracted from BaseCpController rather than copied out of it. The rule -
 * `viewAllSites`, or `editSite:{uid}` per site - used to live only in the
 * controller, so the two dashboard widgets checked `view` and then read
 * whichever site Craft happened to consider current. That is the same
 * permission model the controllers reject a request for, applied nowhere.
 *
 * The controllers throw when the answer is empty and the widgets render
 * nothing, so the decision is here and what to do about it stays with the
 * caller.
 */
final class SiteAccess
{
    /**
     * Every site this user may see analytics for.
     *
     * @return Site[]
     */
    public static function allowed(): array
    {
        $user = Craft::$app->getUser();
        $sites = Craft::$app->getSites()->getAllSites();

        if ($user->checkPermission(Plugin::PERMISSION_VIEW_ALL_SITES)) {
            return array_values($sites);
        }

        return array_values(array_filter(
            $sites,
            static fn(Site $site) => $user->checkPermission('editSite:' . $site->uid),
        ));
    }

    /**
     * The site to report on: the current one when the user may see it, and
     * otherwise the first they may.
     *
     * Null when they may see none, which is not the same as "no site" - it is
     * the case a caller has to handle rather than fall back from.
     */
    public static function current(): ?Site
    {
        $sites = self::allowed();

        if ($sites === []) {
            return null;
        }

        $current = Craft::$app->getSites()->getCurrentSite();

        foreach ($sites as $site) {
            if ($site->id === $current->id) {
                return $site;
            }
        }

        return $sites[0];
    }
}
