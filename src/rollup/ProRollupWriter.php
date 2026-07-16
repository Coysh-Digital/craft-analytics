<?php

namespace coyshdigital\craftanalytics\rollup;

use coyshdigital\craftanalytics\db\Table;
use coyshdigital\craftanalytics\db\Upsert;
use coyshdigital\craftanalytics\enums\AttributionModel;
use coyshdigital\craftanalytics\enums\DimensionType;
use coyshdigital\craftanalytics\models\Campaign;
use coyshdigital\craftanalytics\models\Settings;
use coyshdigital\craftanalytics\Plugin;
use coyshdigital\craftanalytics\services\FunnelsService;
use coyshdigital\craftanalytics\services\GoalsService;
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
    public ?GoalsService $goals = null;
    public ?FunnelsService $funnels = null;
    public ?GoalMatcher $matcher = null;

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

        $conversions = $this->matcher()->conversions($session);

        $this->writeCampaigns($session, $date);
        $this->writeGeo($session, $date);
        $this->writeConversions($session, $date, $conversions);
        $this->writeCampaignConversions($session, $date, $conversions);
        $this->writeFunnels($session, $date);
    }

    /**
     * One row per goal per day, counted once per session.
     *
     * The goals were matched while the hits still existed; all that survives
     * on the session is the set of handles, which is the whole point — a
     * conversion costs one counter, not a stored journey.
     *
     * @param \coyshdigital\craftanalytics\models\Goal[] $conversions
     */
    private function writeConversions(Session $session, string $date, array $conversions): void
    {
        $ids = $this->goals()->idsByHandle();

        foreach ($conversions as $goal) {
            $goalId = $ids[$goal->handle] ?? null;

            if ($goalId === null) {
                continue;
            }

            Upsert::counters($this->db(), Table::GOALS_ROLLUP, [
                'siteId' => $session->siteId,
                'date' => $date,
                'goalId' => $goalId,
            ], [
                'conversions' => 1,
                'value' => $goal->value,
            ]);
        }
    }

    /**
     * Credits the session's conversions to the campaigns that brought it,
     * under the same attribution model and weights the sessions themselves
     * use — so "this campaign drove 12.5 conversions worth £430" adds up
     * against the campaign report rather than telling a second, different
     * story.
     *
     * @param \coyshdigital\craftanalytics\models\Goal[] $conversions
     */
    private function writeCampaignConversions(Session $session, string $date, array $conversions): void
    {
        if (!$this->settings()->enableCampaigns || $session->campaigns === [] || $conversions === []) {
            return;
        }

        $value = array_sum(array_map(static fn($goal): float => $goal->value, $conversions));
        $count = count($conversions);

        $model = AttributionModel::tryFrom($this->settings()->attributionModel) ?? AttributionModel::LastClick;
        $weights = $model->weights(count($session->campaigns));

        foreach ($session->campaigns as $index => $data) {
            $weight = $weights[$index] ?? 0.0;

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
                'conversions' => $count * $weight,
                'value' => $value * $weight,
            ]);
        }
    }

    /**
     * How far this session walked each funnel.
     *
     * A session that reached step 3 counts at steps 1, 2 and 3 — the number a
     * funnel chart wants is "how many got at least this far", and drop-off is
     * then the difference between neighbours. Storing only the deepest step
     * would make every step's number mean something subtly different.
     */
    private function writeFunnels(Session $session, string $date): void
    {
        foreach ($this->funnels()->enabledForSite($session->siteId) as $funnel) {
            $reached = $funnel->reachedStep($session);

            for ($position = 1; $position <= $reached; $position++) {
                Upsert::counters($this->db(), Table::FUNNEL_STEP_ROLLUP, [
                    'siteId' => $session->siteId,
                    'date' => $date,
                    'funnelId' => $funnel->id,
                    'position' => $position,
                ], [
                    'sessions' => 1,
                ]);
            }
        }
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

    private function goals(): GoalsService
    {
        return $this->goals ??= Plugin::getInstance()->getGoals();
    }

    private function funnels(): FunnelsService
    {
        return $this->funnels ??= Plugin::getInstance()->getFunnels();
    }

    private function matcher(): GoalMatcher
    {
        return $this->matcher ??= new GoalMatcher($this->goals(), $this->isPro());
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
