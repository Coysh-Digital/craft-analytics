<?php

namespace coyshdigital\craftanalytics;

use coyshdigital\craftanalytics\assets\CpAsset;
use coyshdigital\craftanalytics\ingest\CaptureService;
use coyshdigital\craftanalytics\ingest\NonceRegistry;
use coyshdigital\craftanalytics\ingest\ScriptInjector;
use coyshdigital\craftanalytics\models\DateRange;
use coyshdigital\craftanalytics\models\Settings;
use coyshdigital\craftanalytics\rollup\DbRollupSink;
use coyshdigital\craftanalytics\rollup\RollupSinkInterface;
use coyshdigital\craftanalytics\services\BotFilter;
use coyshdigital\craftanalytics\services\ChannelClassifier;
use coyshdigital\craftanalytics\services\ConsentService;
use coyshdigital\craftanalytics\services\ContentStatsService;
use coyshdigital\craftanalytics\services\ConversionStatsService;
use coyshdigital\craftanalytics\services\DeviceParser;
use coyshdigital\craftanalytics\services\DimensionsService;
use coyshdigital\craftanalytics\services\FunnelsService;
use coyshdigital\craftanalytics\services\GcService;
use coyshdigital\craftanalytics\services\GeoService;
use coyshdigital\craftanalytics\services\GoalsService;
use coyshdigital\craftanalytics\services\IdentityService;
use coyshdigital\craftanalytics\services\PrivacyDocumentService;
use coyshdigital\craftanalytics\services\PrivacyService;
use coyshdigital\craftanalytics\services\SaltService;
use coyshdigital\craftanalytics\services\StatsService;
use coyshdigital\craftanalytics\session\SessionStore;
use coyshdigital\craftanalytics\uniques\ExactUniqueCounter;
use coyshdigital\craftanalytics\uniques\HllUniqueCounter;
use coyshdigital\craftanalytics\uniques\RedisUniqueCounter;
use coyshdigital\craftanalytics\uniques\UniqueCounterInterface;
use coyshdigital\craftanalytics\variables\CraftAnalyticsVariable;
use coyshdigital\craftanalytics\widgets\OverviewWidget;
use coyshdigital\craftanalytics\write\DirectWriter;
use coyshdigital\craftanalytics\write\QueueWriter;
use coyshdigital\craftanalytics\write\SpoolWriter;
use coyshdigital\craftanalytics\write\WriterInterface;
use Craft;
use craft\base\Model;
use craft\base\Plugin as BasePlugin;
use craft\elements\Entry;
use craft\events\DefineAttributeHtmlEvent;
use craft\events\DefineHtmlEvent;
use craft\events\RebuildConfigEvent;
use craft\events\RegisterComponentTypesEvent;
use craft\events\RegisterElementTableAttributesEvent;
use craft\events\RegisterUrlRulesEvent;
use craft\events\RegisterUserPermissionsEvent;
use craft\helpers\Html;
use craft\services\Dashboard;
use craft\services\Gc;
use craft\services\ProjectConfig;
use craft\services\UserPermissions;
use craft\web\Application as WebApplication;
use craft\web\Request as WebRequest;
use craft\web\Response;
use craft\web\twig\variables\CraftVariable;
use craft\web\UrlManager;
use craft\web\View;
use yii\base\Event;

/**
 * Craft Analytics — privacy-first, consent-aware analytics for Craft CMS.
 *
 * @method static Plugin getInstance()
 * @method Settings getSettings()
 * @property-read DimensionsService $dimensions
 * @property-read SaltService $salts
 * @property-read IdentityService $identity
 * @property-read BotFilter $bots
 * @property-read SessionStore $sessions
 * @property-read CaptureService $capture
 * @property-read ChannelClassifier $channels
 * @property-read DeviceParser $deviceParser
 */
class Plugin extends BasePlugin
{
    public const EDITION_LITE = 'lite';
    public const EDITION_PRO = 'pro';

    public const PERMISSION_VIEW = 'craftAnalytics:view';
    public const PERMISSION_VIEW_ALL_SITES = 'craftAnalytics:viewAllSites';
    public const PERMISSION_EXPORT = 'craftAnalytics:export';
    public const PERMISSION_MANAGE_SETTINGS = 'craftAnalytics:manageSettings';

    public string $schemaVersion = '1.3.0';
    public bool $hasCpSettings = true;
    public bool $hasCpSection = true;

    private ?WriterInterface $writer = null;
    private ?UniqueCounterInterface $uniqueCounter = null;

    /** @var array<int,array<int,int>>|null siteId => elementId => views */
    private ?array $indexViews = null;

    public static function editions(): array
    {
        return [
            self::EDITION_LITE,
            self::EDITION_PRO,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public static function config(): array
    {
        return [
            'components' => [
                'dimensions' => DimensionsService::class,
                'salts' => SaltService::class,
                'identity' => IdentityService::class,
                'bots' => BotFilter::class,
                'sessions' => SessionStore::class,
                'capture' => CaptureService::class,
                'nonces' => NonceRegistry::class,
                'scriptInjector' => ScriptInjector::class,
                'channels' => ChannelClassifier::class,
                'deviceParser' => DeviceParser::class,
                'rollupSink' => DbRollupSink::class,
                'stats' => StatsService::class,
                'geo' => GeoService::class,
                'consent' => ConsentService::class,
                'privacy' => PrivacyService::class,
                'privacyDocuments' => PrivacyDocumentService::class,
                'goals' => GoalsService::class,
                'funnels' => FunnelsService::class,
                'contentStats' => ContentStatsService::class,
                'conversionStats' => ConversionStatsService::class,
            ],
        ];
    }

    public function init(): void
    {
        parent::init();
        $this->attachEventHandlers();
    }

    public function getDimensions(): DimensionsService
    {
        /** @var DimensionsService */
        return $this->get('dimensions');
    }

    public function getSalts(): SaltService
    {
        /** @var SaltService */
        return $this->get('salts');
    }

    public function getIdentity(): IdentityService
    {
        /** @var IdentityService */
        return $this->get('identity');
    }

    public function getBots(): BotFilter
    {
        /** @var BotFilter */
        return $this->get('bots');
    }

    public function getSessions(): SessionStore
    {
        /** @var SessionStore */
        return $this->get('sessions');
    }

    public function getCapture(): CaptureService
    {
        /** @var CaptureService */
        return $this->get('capture');
    }

    public function getNonces(): NonceRegistry
    {
        /** @var NonceRegistry */
        return $this->get('nonces');
    }

    public function getScriptInjector(): ScriptInjector
    {
        /** @var ScriptInjector */
        return $this->get('scriptInjector');
    }

    /**
     * Whether Blitz is installed and caching pages.
     *
     * Matters because a cached page never reaches PHP, so server-only
     * tracking silently under-counts — the failure mode nobody notices,
     * because the numbers still look plausible.
     */
    public function isFullPageCachingLikely(): bool
    {
        $plugins = Craft::$app->getPlugins();

        foreach (['blitz', 'static-cache'] as $handle) {
            if ($plugins->isPluginEnabled($handle)) {
                return true;
            }
        }

        return false;
    }

    public function getChannels(): ChannelClassifier
    {
        /** @var ChannelClassifier */
        return $this->get('channels');
    }

    public function getDeviceParser(): DeviceParser
    {
        /** @var DeviceParser */
        return $this->get('deviceParser');
    }

    /**
     * Where drained batches land.
     */
    public function getRollupSink(): RollupSinkInterface
    {
        /** @var RollupSinkInterface */
        return $this->get('rollupSink');
    }

    public function getGoals(): GoalsService
    {
        /** @var GoalsService */
        return $this->get('goals');
    }

    public function getFunnels(): FunnelsService
    {
        /** @var FunnelsService */
        return $this->get('funnels');
    }

    public function getContentStats(): ContentStatsService
    {
        /** @var ContentStatsService */
        return $this->get('contentStats');
    }

    public function getConversionStats(): ConversionStatsService
    {
        /** @var ConversionStatsService */
        return $this->get('conversionStats');
    }

    /**
     * The configured unique-visitor counter.
     *
     * `auto` prefers Redis when the site already has it (native, mergeable,
     * effectively free) and otherwise falls back to the portable sketch —
     * which needs no infrastructure and costs ±1.6%.
     */
    public function getUniqueCounter(): UniqueCounterInterface
    {
        return $this->uniqueCounter ??= match ($this->getSettings()->uniqueCounterDriver) {
            Settings::UNIQUES_DRIVER_REDIS => new RedisUniqueCounter(),
            Settings::UNIQUES_DRIVER_EXACT => new ExactUniqueCounter(),
            Settings::UNIQUES_DRIVER_HLL => new HllUniqueCounter(),
            default => RedisUniqueCounter::isAvailable()
                ? new RedisUniqueCounter()
                : new HllUniqueCounter(),
        };
    }

    /**
     * The configured write path, falling back to the spool when `queue` is
     * selected without its component — dropping hits silently would be worse
     * than ignoring the setting loudly.
     */
    public function getWriter(): WriterInterface
    {
        return $this->writer ??= match ($this->getSettings()->writeDriver) {
            Settings::WRITE_DRIVER_DIRECT => new DirectWriter(),
            Settings::WRITE_DRIVER_QUEUE => QueueWriter::isConfigured()
                ? new QueueWriter()
                : $this->spoolFallback(),
            default => new SpoolWriter(),
        };
    }

    public function getStats(): StatsService
    {
        /** @var StatsService */
        return $this->get('stats');
    }

    public function getGeo(): GeoService
    {
        /** @var GeoService */
        return $this->get('geo');
    }

    public function getConsent(): ConsentService
    {
        /** @var ConsentService */
        return $this->get('consent');
    }

    public function getPrivacy(): PrivacyService
    {
        /** @var PrivacyService */
        return $this->get('privacy');
    }

    public function getPrivacyDocuments(): PrivacyDocumentService
    {
        /** @var PrivacyDocumentService */
        return $this->get('privacyDocuments');
    }

    /**
     * @return array<string,mixed>|null
     */
    public function getCpNavItem(): ?array
    {
        if (!Craft::$app->getUser()->checkPermission(self::PERMISSION_VIEW)) {
            return null;
        }

        $item = parent::getCpNavItem();

        if ($item === null) {
            return null;
        }

        $item['label'] = Craft::t('craft-analytics', 'Analytics');
        $item['icon'] = '@coyshdigital/craftanalytics/resources/icon-mask.svg';
        $item['subnav'] = [
            'dashboard' => ['label' => Craft::t('craft-analytics', 'Dashboard'), 'url' => 'craft-analytics'],
            'realtime' => ['label' => Craft::t('craft-analytics', 'Real-time'), 'url' => 'craft-analytics/realtime'],
            'pages' => ['label' => Craft::t('craft-analytics', 'Pages'), 'url' => 'craft-analytics/pages'],
            'content' => ['label' => Craft::t('craft-analytics', 'Content'), 'url' => 'craft-analytics/content'],
            'sources' => ['label' => Craft::t('craft-analytics', 'Sources'), 'url' => 'craft-analytics/sources'],
            'devices' => ['label' => Craft::t('craft-analytics', 'Devices'), 'url' => 'craft-analytics/devices'],
            'campaigns' => ['label' => Craft::t('craft-analytics', 'Campaigns'), 'url' => 'craft-analytics/campaigns'],
            'geo' => ['label' => Craft::t('craft-analytics', 'Locations'), 'url' => 'craft-analytics/geo'],
            'events' => ['label' => Craft::t('craft-analytics', 'Events'), 'url' => 'craft-analytics/events'],
            'goals' => ['label' => Craft::t('craft-analytics', 'Goals'), 'url' => 'craft-analytics/goals'],
            'funnels' => ['label' => Craft::t('craft-analytics', 'Funnels'), 'url' => 'craft-analytics/funnels'],
            'privacy' => ['label' => Craft::t('craft-analytics', 'Privacy'), 'url' => 'craft-analytics/privacy'],
        ];

        return $item;
    }

    protected function createSettingsModel(): ?Model
    {
        return new Settings();
    }

    protected function settingsHtml(): ?string
    {
        return Craft::$app->getView()->renderTemplate('craft-analytics/_settings.twig', [
            'plugin' => $this,
            'settings' => $this->getSettings(),
        ]);
    }

    private function spoolFallback(): SpoolWriter
    {
        Craft::warning(
            'craft-analytics: writeDriver is "queue" but no ' . QueueWriter::COMPONENT_ID
            . ' component is configured; falling back to the spool driver.',
            __METHOD__,
        );

        return new SpoolWriter();
    }

    /**
     * Captures the pageview once the response is on the wire.
     *
     * EVENT_AFTER_SEND fires after sendContent(), and the first thing the
     * handler does is close the FPM connection — so no work here can touch
     * time-to-first-byte (C1). The visitor already has the page; we are just
     * a process that hasn't exited yet.
     */
    private function attachCapture(): void
    {
        if (Craft::$app instanceof WebApplication === false) {
            return;
        }

        Event::on(
            Response::class,
            Response::EVENT_AFTER_SEND,
            function(Event $event) {
                $response = $event->sender;
                $request = Craft::$app->getRequest();

                if (!$response instanceof Response || !$request instanceof WebRequest) {
                    return;
                }

                // Cheap enough to run before the flush; anything heavier
                // would be paid for by the visitor.
                if (!$this->getCapture()->isTrackable($request, $response)) {
                    return;
                }

                self::closeConnection();

                try {
                    $this->getCapture()->capture($request, $response);
                } catch (\Throwable $e) {
                    // The page is already delivered; a failure here must stay
                    // invisible to the visitor.
                    Craft::warning('craft-analytics capture failed: ' . $e->getMessage(), __METHOD__);
                }
            },
        );
    }

    /**
     * Hands the connection back to the visitor before we do any work.
     *
     * Under PHP-FPM this is exact. Under other SAPIs there is no equivalent,
     * so the guard below simply does nothing and capture stays cheap enough
     * (~1–2 ms) that it doesn't matter — see docs/performance.md.
     */
    private static function closeConnection(): void
    {
        if (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
        } elseif (function_exists('litespeed_finish_request')) {
            litespeed_finish_request();
        }
    }

    /**
     * Injects the tracker just before </body>, and registers the beacon's
     * site route.
     */
    private function attachBeacon(): void
    {
        if (Craft::$app instanceof WebApplication === false) {
            return;
        }

        Event::on(
            UrlManager::class,
            UrlManager::EVENT_REGISTER_SITE_URL_RULES,
            function(RegisterUrlRulesEvent $event) {
                $settings = $this->getSettings();
                $event->rules[$settings->beaconPath] = 'craft-analytics/beacon/index';
                $event->rules[$settings->consentPath] = 'craft-analytics/consent/index';
            },
        );

        Event::on(
            View::class,
            View::EVENT_END_BODY,
            function() {
                try {
                    echo $this->getScriptInjector()->tag() ?? '';
                } catch (\Throwable $e) {
                    // A tracker that can't be placed is not a reason to break
                    // someone's page.
                    Craft::warning('craft-analytics could not inject its script: ' . $e->getMessage(), __METHOD__);
                }
            },
        );
    }

    /**
     * Traffic where the content is.
     *
     * Stats in the entry editor and a views column on the entries index are
     * the things a generic analytics tool structurally cannot do: it has no
     * idea what an entry is.
     */
    private function attachElementIntegration(): void
    {
        Event::on(
            Entry::class,
            Entry::EVENT_DEFINE_SIDEBAR_HTML,
            function(DefineHtmlEvent $event) {
                $entry = $event->sender;

                if (!$entry instanceof Entry || !$entry->id || $entry->siteId === null) {
                    return;
                }

                if (!Craft::$app->getUser()->checkPermission(self::PERMISSION_VIEW)) {
                    return;
                }

                try {
                    $range = DateRange::fromPreset(DateRange::PRESET_30_DAYS);
                    $stats = $this->getStats()->elementStats($entry->siteId, $entry->id, $range);

                    Craft::$app->getView()->registerAssetBundle(CpAsset::class);

                    $event->html .= Craft::$app->getView()->renderTemplate('craft-analytics/_partials/sidebar.twig', [
                        'stats' => $stats,
                        'entry' => $entry,
                    ]);
                } catch (\Throwable $e) {
                    // Never take the entry editor down over a stats panel.
                    Craft::warning('craft-analytics sidebar failed: ' . $e->getMessage(), __METHOD__);
                }
            },
        );

        Event::on(
            Entry::class,
            Entry::EVENT_REGISTER_TABLE_ATTRIBUTES,
            static function(RegisterElementTableAttributesEvent $event) {
                $event->tableAttributes['craftAnalyticsViews'] = [
                    'label' => Craft::t('craft-analytics', 'Views (30d)'),
                ];
            },
        );

        Event::on(
            Entry::class,
            Entry::EVENT_DEFINE_ATTRIBUTE_HTML,
            function(DefineAttributeHtmlEvent $event) {
                if ($event->attribute !== 'craftAnalyticsViews') {
                    return;
                }

                $entry = $event->sender;

                if (!$entry instanceof Entry || !$entry->id || $entry->siteId === null) {
                    return;
                }

                if (!Craft::$app->getUser()->checkPermission(self::PERMISSION_VIEW)) {
                    $event->html = '';
                    return;
                }

                $views = $this->elementIndexViews($entry->siteId, $entry->id);
                $event->html = $views === 0
                    ? '<span class="light">—</span>'
                    : Html::encode(Craft::$app->getFormatter()->asDecimal($views, 0));
            },
        );
    }

    /**
     * Views for one entry on an element index.
     *
     * Craft renders index rows one at a time, so this is memoised per request
     * to keep a 100-row page to one query rather than a hundred.
     */
    private function elementIndexViews(int $siteId, int $elementId): int
    {
        $this->indexViews ??= [];

        if (!isset($this->indexViews[$siteId])) {
            $this->indexViews[$siteId] = [];
        }

        if (!array_key_exists($elementId, $this->indexViews[$siteId])) {
            $range = DateRange::fromPreset(DateRange::PRESET_30_DAYS);
            $ids = array_slice(array_keys($this->indexViews[$siteId] + [$elementId => 0]), 0, 200);
            $this->indexViews[$siteId] += $this->getStats()->viewsByElement($siteId, $ids, $range);
            $this->indexViews[$siteId][$elementId] ??= 0;
        }

        return $this->indexViews[$siteId][$elementId];
    }

    /**
     * Keeps the goal and funnel tables in step with project config, which is
     * the source of truth for both.
     *
     * Goals are applied before funnels, and the handler re-asserts that
     * ordering itself: a funnel step points at a goal, and applying them the
     * other way round would drop every step on a fresh environment.
     */
    private function attachProjectConfig(): void
    {
        $projectConfig = Craft::$app->getProjectConfig();

        $projectConfig
            ->onAdd(GoalsService::CONFIG_PATH . '.{uid}', [$this->getGoals(), 'handleChangedGoal'])
            ->onUpdate(GoalsService::CONFIG_PATH . '.{uid}', [$this->getGoals(), 'handleChangedGoal'])
            ->onRemove(GoalsService::CONFIG_PATH . '.{uid}', [$this->getGoals(), 'handleDeletedGoal'])
            ->onAdd(FunnelsService::CONFIG_PATH . '.{uid}', [$this->getFunnels(), 'handleChangedFunnel'])
            ->onUpdate(FunnelsService::CONFIG_PATH . '.{uid}', [$this->getFunnels(), 'handleChangedFunnel'])
            ->onRemove(FunnelsService::CONFIG_PATH . '.{uid}', [$this->getFunnels(), 'handleDeletedFunnel']);

        Event::on(
            ProjectConfig::class,
            ProjectConfig::EVENT_REBUILD,
            function(RebuildConfigEvent $event) {
                $event->config['craftAnalytics']['goals'] = $this->getGoals()->rebuildConfig();
                $event->config['craftAnalytics']['funnels'] = $this->getFunnels()->rebuildConfig();
            },
        );
    }

    private function attachEventHandlers(): void
    {
        $this->attachCapture();
        $this->attachBeacon();
        $this->attachProjectConfig();

        // Craft's GC is a convenience, not the guarantee — the console
        // command is what a site should schedule (see GcController).
        Event::on(
            Gc::class,
            Gc::EVENT_RUN,
            static function() {
                (new GcService())->run();
            },
        );

        Event::on(
            UrlManager::class,
            UrlManager::EVENT_REGISTER_CP_URL_RULES,
            static function(RegisterUrlRulesEvent $event) {
                $event->rules['craft-analytics'] = 'craft-analytics/dashboard/index';
                $event->rules['craft-analytics/realtime'] = 'craft-analytics/reports/realtime';
                $event->rules['craft-analytics/realtime-data'] = 'craft-analytics/reports/realtime-data';
                $event->rules['craft-analytics/pages'] = 'craft-analytics/reports/pages';
                $event->rules['craft-analytics/sources'] = 'craft-analytics/reports/sources';
                $event->rules['craft-analytics/devices'] = 'craft-analytics/reports/devices';
                $event->rules['craft-analytics/privacy'] = 'craft-analytics/privacy/index';
                $event->rules['craft-analytics/campaigns'] = 'craft-analytics/pro-reports/campaigns';
                $event->rules['craft-analytics/geo'] = 'craft-analytics/pro-reports/geo';
                $event->rules['craft-analytics/events'] = 'craft-analytics/pro-reports/events';
                $event->rules['craft-analytics/content'] = 'craft-analytics/content/index';
                $event->rules['craft-analytics/content/section/<sectionId:\d+>'] = 'craft-analytics/content/section';
                $event->rules['craft-analytics/goals'] = 'craft-analytics/conversions/goals';
                $event->rules['craft-analytics/funnels'] = 'craft-analytics/conversions/funnels';

                // Definitions live under Settings, where the rest of the
                // things that write project config live.
                $event->rules['settings/plugins/craft-analytics/goals'] = 'craft-analytics/goals/index';
                $event->rules['settings/plugins/craft-analytics/goals/new'] = 'craft-analytics/goals/edit';
                $event->rules['settings/plugins/craft-analytics/goals/<uid:{uid}>'] = 'craft-analytics/goals/edit';
                $event->rules['settings/plugins/craft-analytics/funnels/new'] = 'craft-analytics/goals/edit-funnel';
                $event->rules['settings/plugins/craft-analytics/funnels/<uid:{uid}>'] = 'craft-analytics/goals/edit-funnel';

                foreach (['pages', 'sources', 'devices', 'trend', 'content', 'goals'] as $kind) {
                    $event->rules["craft-analytics/export/$kind"] = "craft-analytics/export/$kind";
                }
            },
        );

        Event::on(
            Dashboard::class,
            Dashboard::EVENT_REGISTER_WIDGET_TYPES,
            static function(RegisterComponentTypesEvent $event) {
                $event->types[] = OverviewWidget::class;
            },
        );

        Event::on(
            CraftVariable::class,
            CraftVariable::EVENT_INIT,
            static function(Event $event) {
                /** @var CraftVariable $variable */
                $variable = $event->sender;
                $variable->set('craftAnalytics', CraftAnalyticsVariable::class);
            },
        );

        $this->attachElementIntegration();

        Event::on(
            UserPermissions::class,
            UserPermissions::EVENT_REGISTER_PERMISSIONS,
            static function(RegisterUserPermissionsEvent $event) {
                $event->permissions[] = [
                    'heading' => Craft::t('craft-analytics', 'Craft Analytics'),
                    'permissions' => [
                        self::PERMISSION_VIEW => [
                            'label' => Craft::t('craft-analytics', 'View analytics'),
                            'info' => Craft::t('craft-analytics', 'Limited to the sites the user can edit, unless “view all sites” is also granted.'),
                            'nested' => [
                                self::PERMISSION_VIEW_ALL_SITES => [
                                    'label' => Craft::t('craft-analytics', 'View analytics for all sites'),
                                ],
                                self::PERMISSION_EXPORT => [
                                    'label' => Craft::t('craft-analytics', 'Export analytics data'),
                                ],
                            ],
                        ],
                        self::PERMISSION_MANAGE_SETTINGS => [
                            'label' => Craft::t('craft-analytics', 'Manage plugin settings'),
                        ],
                    ],
                ];
            },
        );
    }
}
