<?php

namespace coyshdigital\craftanalytics;

use coyshdigital\craftanalytics\models\Settings;
use coyshdigital\craftanalytics\services\DimensionsService;
use Craft;
use craft\base\Model;
use craft\base\Plugin as BasePlugin;
use craft\events\RegisterUrlRulesEvent;
use craft\events\RegisterUserPermissionsEvent;
use craft\services\UserPermissions;
use craft\web\UrlManager;
use yii\base\Event;

/**
 * Craft Analytics — privacy-first, consent-aware analytics for Craft CMS.
 *
 * @method static Plugin getInstance()
 * @method Settings getSettings()
 * @property-read DimensionsService $dimensions
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

    private function attachEventHandlers(): void
    {
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
