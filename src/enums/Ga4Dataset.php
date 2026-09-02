<?php

namespace coyshdigital\craftanalytics\enums;

/**
 * The GA4 reports the history import pulls, and how each maps onto the plugin's
 * rollups.
 *
 * The wizard offers four groups (pages, sources, devices, events); each expands
 * to one or more of these, because a single plugin report is sometimes two GA4
 * queries. "Pages & daily totals" needs both per-page rows and a site-wide
 * daily total for the sessions rollup, and "sources" carries campaigns
 * alongside it.
 *
 * Everything here is a *daily* aggregate: GA4's `date` dimension leads every
 * query, and the rows land at `hour = -1`, the same shape the nightly compactor
 * leaves behind, so imported days are indistinguishable from lived ones.
 */
enum Ga4Dataset: string
{
    case Pages = 'pages';
    case Totals = 'totals';
    case Sources = 'sources';
    case Campaigns = 'campaigns';
    case Devices = 'devices';
    case Geo = 'geo';
    case Events = 'events';

    /** The wizard groups, as offered to the operator. */
    public const GROUP_PAGES = 'pages';
    public const GROUP_SOURCES = 'sources';
    public const GROUP_DEVICES = 'devices';
    public const GROUP_EVENTS = 'events';

    /**
     * All groups, for the wizard's checkboxes.
     *
     * @return string[]
     */
    public static function groups(): array
    {
        return [self::GROUP_PAGES, self::GROUP_SOURCES, self::GROUP_DEVICES, self::GROUP_EVENTS];
    }

    /**
     * The datasets a set of chosen groups expands to, in a stable order.
     *
     * @param string[] $groups
     * @return self[]
     */
    public static function forGroups(array $groups): array
    {
        $datasets = [];

        foreach (self::cases() as $dataset) {
            if (in_array($dataset->group(), $groups, true)) {
                $datasets[] = $dataset;
            }
        }

        return $datasets;
    }

    /** The wizard group this dataset belongs to. */
    public function group(): string
    {
        return match ($this) {
            self::Pages, self::Totals => self::GROUP_PAGES,
            self::Sources, self::Campaigns => self::GROUP_SOURCES,
            self::Devices, self::Geo => self::GROUP_DEVICES,
            self::Events => self::GROUP_EVENTS,
        };
    }

    /**
     * Whether this dataset writes a Pro-only rollup. On Lite these are filtered
     * out of the import, exactly as live capture never writes them.
     */
    public function isPro(): bool
    {
        return match ($this) {
            self::Campaigns, self::Geo, self::Events => true,
            default => false,
        };
    }

    /**
     * The GA4 Data API dimension names, `date` always first.
     *
     * @return string[]
     */
    public function dimensions(): array
    {
        return match ($this) {
            self::Pages => ['date', 'pagePath'],
            self::Totals => ['date'],
            self::Sources => ['date', 'sessionDefaultChannelGroup', 'sessionSource'],
            self::Campaigns => ['date', 'sessionSource', 'sessionMedium', 'sessionCampaignName'],
            self::Devices => ['date', 'browser', 'operatingSystem', 'deviceCategory'],
            // countryId is the ISO 3166-1 alpha-2 code the geo rollup stores;
            // `country` would be the display name, which does not fit char(2).
            self::Geo => ['date', 'countryId', 'region'],
            self::Events => ['date', 'eventName'],
        };
    }

    /**
     * The GA4 Data API metric names.
     *
     * @return string[]
     */
    public function metrics(): array
    {
        return match ($this) {
            self::Pages => ['screenPageViews', 'activeUsers', 'userEngagementDuration'],
            self::Totals => ['sessions', 'bounceRate', 'userEngagementDuration', 'screenPageViews', 'activeUsers'],
            self::Sources => ['sessions', 'bounceRate'],
            self::Campaigns => ['sessions', 'bounceRate'],
            self::Devices => ['sessions'],
            self::Geo => ['sessions'],
            self::Events => ['eventCount'],
        };
    }
}
