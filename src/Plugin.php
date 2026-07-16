<?php

namespace coyshdigital\craftanalytics;

use coyshdigital\craftanalytics\ingest\CaptureService;
use coyshdigital\craftanalytics\models\Settings;
use coyshdigital\craftanalytics\rollup\NullRollupSink;
use coyshdigital\craftanalytics\rollup\RollupSinkInterface;
use coyshdigital\craftanalytics\services\BotFilter;
use coyshdigital\craftanalytics\services\DimensionsService;
use coyshdigital\craftanalytics\services\IdentityService;
use coyshdigital\craftanalytics\services\SaltService;
use coyshdigital\craftanalytics\session\SessionStore;
use coyshdigital\craftanalytics\write\DirectWriter;
use coyshdigital\craftanalytics\write\QueueWriter;
use coyshdigital\craftanalytics\write\SpoolWriter;
use coyshdigital\craftanalytics\write\WriterInterface;
use Craft;
use craft\base\Model;
use craft\base\Plugin as BasePlugin;
use craft\events\RegisterUrlRulesEvent;
use craft\events\RegisterUserPermissionsEvent;
use craft\services\UserPermissions;
use craft\web\Application as WebApplication;
use craft\web\Request as WebRequest;
use craft\web\Response;
use craft\web\UrlManager;
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
 */
class Plugin extends BasePlugin
{
    public const EDITION_LITE = 'lite';
    public const EDITION_PRO = 'pro';

    public const PERMISSION_VIEW = 'craftAnalytics:view';
    public const PERMISSION_EXPORT = 'craftAnalytics:export';
    public const PERMISSION_MANAGE_SETTINGS = 'craftAnalytics:manageSettings';

    public string $schemaVersion = '1.0.0';
    public bool $hasCpSettings = true;
    public bool $hasCpSection = true;

    private ?WriterInterface $writer = null;

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
                'rollupSink' => NullRollupSink::class,
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

    /**
     * Where drained batches land. Phase 2 ships the null sink; phase 3
     * swaps in the rollup-table implementation.
     */
    public function getRollupSink(): RollupSinkInterface
    {
        /** @var RollupSinkInterface */
        return $this->get('rollupSink');
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

    /**
     * @return array<string,mixed>|null
     */
    public function getCpNavItem(): ?array
    {
        $item = parent::getCpNavItem();

        if ($item !== null) {
            $item['label'] = Craft::t('craft-analytics', 'Analytics');
        }

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

    private function attachEventHandlers(): void
    {
        $this->attachCapture();

        Event::on(
            UrlManager::class,
            UrlManager::EVENT_REGISTER_CP_URL_RULES,
            static function(RegisterUrlRulesEvent $event) {
                $event->rules['craft-analytics'] = 'craft-analytics/dashboard/index';
            },
        );

        Event::on(
            UserPermissions::class,
            UserPermissions::EVENT_REGISTER_PERMISSIONS,
            static function(RegisterUserPermissionsEvent $event) {
                $event->permissions[] = [
                    'heading' => Craft::t('craft-analytics', 'Craft Analytics'),
                    'permissions' => [
                        self::PERMISSION_VIEW => [
                            'label' => Craft::t('craft-analytics', 'View analytics'),
                        ],
                        self::PERMISSION_EXPORT => [
                            'label' => Craft::t('craft-analytics', 'Export analytics data'),
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
