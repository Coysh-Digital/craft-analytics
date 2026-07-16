<?php

namespace coyshdigital\craftanalytics\rollup;

use coyshdigital\craftanalytics\db\Table;
use coyshdigital\craftanalytics\db\Upsert;
use coyshdigital\craftanalytics\enums\AttributionModel;
use coyshdigital\craftanalytics\enums\DimensionType;
use coyshdigital\craftanalytics\models\Campaign;
use coyshdigital\craftanalytics\models\Settings;
use coyshdigital\craftanalytics\Plugin;
use coyshdigital\craftanalytics\session\Session;
use Craft;
use yii\base\Component;
use yii\db\Connection;

/**
 * The Pro rollups: campaigns and geo, written when a session closes.
 *
 * Session-scoped, both of them: a campaign brings somebody to the site once,
 * and they are in one place for the visit. Recording either per-pageview
 * would multiply a single fact by however many pages they happened to read.
 *
 * Everything here is edition-gated at the service layer, so a Lite install
 * writes nothing no matter what route or event is reached.
 */
class ProRollupWriter extends Component
{
    public ?Connection $db = null;
    public ?Settings $settings = null;
    public ?DimensionCapper $capper = null;

    /** Edition override; defaults to the plugin's own. Set in tests. */
    public ?bool $isPro = null;

    public function isPro(): bool
    {
        return $this->isPro ??= Plugin::getInstance()->is(Plugin::EDITION_PRO);
    }

    /**
     * Writes the Pro rollups a closed session contributes to.
     */
    public function writeSession(Session $session, string $date): void
    {
        if (!$this->isPro()) {
            return;
        }

        $this->writeCampaigns($session, $date);
        $this->writeGeo($session, $date);
    }

    /**
     * Writes a batch's events, scroll depths, outbound clicks and searches.
     */
    public function writeInteractions(InteractionBuckets $interactions): void
    {
        if (!$this->isPro() || !$this->settings()->enableEvents) {
            return;
        }

        foreach ($interactions->events as $event) {
            Upsert::counters($this->db(), Table::EVENTS_ROLLUP, [
                'siteId' => $event['siteId'],
                'date' => $event['date'],
                'hour' => $event['hour'],
                'eventNameDimId' => $this->dimId($event['siteId'], $event['date'], DimensionType::EventName, $event['name']),
                'pathDimId' => $this->dimId($event['siteId'], $event['date'], DimensionType::Path, $event['path']),
            ], [
                'count' => $event['count'],
                'sumValue' => $event['value'],
            ]);
        }

        foreach ($interactions->scroll as $scroll) {
            Upsert::counters($this->db(), Table::SCROLL_ROLLUP, [
                'siteId' => $scroll['siteId'],
                'date' => $scroll['date'],
                'pathDimId' => $this->dimId($scroll['siteId'], $scroll['date'], DimensionType::Path, $scroll['path']),
                'bucket' => $scroll['bucket'],
            ], [
                'count' => $scroll['count'],
            ]);
        }

        foreach ($interactions->outbound as $outbound) {
            Upsert::counters($this->db(), Table::OUTBOUND_ROLLUP, [
                'siteId' => $outbound['siteId'],
                'date' => $outbound['date'],
                'targetHostDimId' => $this->dimId($outbound['siteId'], $outbound['date'], DimensionType::OutboundHost, $outbound['host']),
                'targetDimId' => $this->dimId($outbound['siteId'], $outbound['date'], DimensionType::OutboundUrl, $outbound['url']),
                'pathDimId' => $this->dimId($outbound['siteId'], $outbound['date'], DimensionType::Path, $outbound['path']),
            ], [
                'count' => $outbound['count'],
            ]);
        }

        foreach ($interactions->searches as $search) {
            Upsert::counters($this->db(), Table::SEARCH_ROLLUP, [
                'siteId' => $search['siteId'],
                'date' => $search['date'],
                'termDimId' => $this->dimId($search['siteId'], $search['date'], DimensionType::SearchTerm, $search['term']),
            ], [
                'count' => $search['count'],
                'zeroResults' => $search['zeroResults'],
            ]);
        }
    }

    /**
     * Credits the session to the campaigns that touched it.
     *
     * The attribution model divides one session between its touches, so the
     * credit sums to exactly one session however many campaigns were
     * involved — inventing a session per touch is how attribution reports end
     * up claiming more traffic than the site had.
     *
     * **What this cannot do**: attribute across sessions. Tier-1 identity is
     * destroyed every time the salt rotates, so there is no way to know that
     * today's visitor is the one who clicked an ad last week. Cross-session
     * attribution windows are only meaningful for consented (Tier-2)
     * visitors, and are deliberately not faked here for anyone else. See
     * docs/pro-analytics.md.
     */
    private function writeCampaigns(Session $session, string $date): void
    {
        if (!$this->settings()->enableCampaigns || $session->campaigns === []) {
            return;
        }

        $model = AttributionModel::tryFrom($this->settings()->attributionModel) ?? AttributionModel::LastClick;
        $weights = $model->weights(count($session->campaigns));
        $isBounce = $session->isBounce();

        foreach ($session->campaigns as $index => $data) {
            $weight = $weights[$index] ?? 0.0;

            // A model that gives a touch no credit gives it no row either.
            if ($weight <= 0.0) {
                continue;
            }

            $campaign = Campaign::fromArray($data);

            if ($campaign === null) {
                continue;
            }

            Upsert::counters($this->db(), Table::CAMPAIGNS_ROLLUP, [
                'siteId' => $session->siteId,
                'date' => $date,
                'sourceDimId' => $this->dimId($session->siteId, $date, DimensionType::CampaignSource, $campaign->source),
                'mediumDimId' => $this->dimId($session->siteId, $date, DimensionType::CampaignMedium, $campaign->medium),
                'campaignDimId' => $this->dimId($session->siteId, $date, DimensionType::CampaignName, $campaign->campaign),
                'termDimId' => $this->dimId($session->siteId, $date, DimensionType::CampaignTerm, $campaign->term),
                'contentDimId' => $this->dimId($session->siteId, $date, DimensionType::CampaignContent, $campaign->content),
            ], [
                'sessions' => $weight,
                'bounces' => $isBounce ? $weight : 0,
            ]);
        }
    }

    private function writeGeo(Session $session, string $date): void
    {
        if (!$this->settings()->enableGeo || $session->countryCode === '') {
            return;
        }

        Upsert::counters($this->db(), Table::GEO_ROLLUP, [
            'siteId' => $session->siteId,
            'date' => $date,
            'countryCode' => $session->countryCode,
            'regionDimId' => $this->dimId($session->siteId, $date, DimensionType::Region, $session->region),
        ], [
            'sessions' => 1,
        ]);
    }

    /**
     * An empty dimension value is 0, not a row: "no medium" is the absence of
     * a value, not a value called "".
     */
    private function dimId(int $siteId, string $date, DimensionType $type, string $value): int
    {
        if ($value === '') {
            return 0;
        }

        return $this->capper()->resolve($siteId, $date, $type, $value);
    }

    private function capper(): DimensionCapper
    {
        return $this->capper ??= new DimensionCapper(['db' => $this->db]);
    }

    private function settings(): Settings
    {
        return $this->settings ??= Plugin::getInstance()->getSettings();
    }

    private function db(): Connection
    {
        return $this->db ??= Craft::$app->getDb();
    }
}
