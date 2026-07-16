<?php

/**
 * Test bootstrap: enough of the Craft/Yii runtime to exercise models, the
 * upsert helper, migrations, and services against a real database — without
 * booting a full Craft application.
 */

const YII_DEBUG = true;
const YII_ENV = 'test';

require dirname(__DIR__) . '/vendor/autoload.php';
require dirname(__DIR__) . '/vendor/craftcms/cms/lib/yii2/Yii.php';
require dirname(__DIR__) . '/vendor/craftcms/cms/src/Craft.php';

/**
 * Minimal stand-in for Craft::$app. Craft's schema builders reach for
 * `Craft::$app->getConfig()->getDb()` (table charset/collation), which doesn't
 * justify booting a full application here. Extend as new code paths need it —
 * or swap for a real Craft test harness if this grows legs.
 */
Craft::$app = new class {
    public string $language = 'en-US';
    public string $sourceLanguage = 'en-US';
    public string $charset = 'UTF-8';

    private ?yii\i18n\I18N $i18n = null;

    public function getI18n(): yii\i18n\I18N
    {
        // The plugin's own category has to be registered here; in a real app
        // Craft does it from the plugin's translations directory.
        return $this->i18n ??= new yii\i18n\I18N([
            'translations' => [
                'craft-analytics' => [
                    'class' => yii\i18n\PhpMessageSource::class,
                    'basePath' => dirname(__DIR__) . '/src/translations',
                    'sourceLanguage' => 'en-US',
                ],
            ],
        ]);
    }

    public function getConfig(): object
    {
        return new class {
            public function getDb(): craft\config\DbConfig
            {
                return new craft\config\DbConfig();
            }

            public function getLoadingConfigFile(): ?string
            {
                return null;
            }
        };
    }

    public function get(string $id, bool $throwException = true): mixed
    {
        return null;
    }

    public function getIsInstalled(): bool
    {
        return true;
    }

    public function getTimeZone(): string
    {
        return 'UTC';
    }
};
