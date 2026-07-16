<?php

namespace coyshdigital\craftanalytics\enums;

/**
 * Dimension value types stored in the shared dimensions lookup table.
 *
 * Rollup tables never store dimension values inline — only int foreign keys
 * into `craftanalytics_dimensions`, keyed by (type, valueHash). Backed by int
 * so the type column stays a SMALLINT.
 */
enum DimensionType: int
{
    case Path = 1;
    case ReferrerHost = 2;
    case Browser = 3;
    case Os = 4;
    case EventName = 5;
    case CampaignSource = 6;
    case CampaignMedium = 7;
    case CampaignName = 8;
    case CampaignTerm = 9;
    case CampaignContent = 10;
    case SearchTerm = 11;
    case OutboundHost = 12;
    case OutboundUrl = 13;
    case Region = 14;

    /**
     * Reserved dimension value that per-(site, day, type) cardinality-capped
     * tails are folded into during the drain.
     */
    public const OTHER_VALUE = '__other__';
}
