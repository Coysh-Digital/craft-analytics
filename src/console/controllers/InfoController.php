<?php

namespace coyshdigital\craftanalytics\console\controllers;

use coyshdigital\craftanalytics\Plugin;
use craft\console\Controller;
use craft\helpers\Console;
use yii\console\ExitCode;

/**
 * Prints plugin status — a console smoke test for the wiring.
 */
class InfoController extends Controller
{
    /**
     * Show the plugin edition and effective settings.
     */
    public function actionIndex(): int
    {
        $plugin = Plugin::getInstance();
        $settings = $plugin->getSettings();

        $this->stdout("Craft Analytics {$plugin->getVersion()}\n", Console::FG_GREEN);
        $this->stdout("Edition: {$plugin->edition}\n");
        $this->stdout("Tracking mode: {$settings->trackingMode}\n");
        $this->stdout("Write driver: {$settings->writeDriver}\n");
        $this->stdout("Unique counter: {$settings->uniqueCounterDriver}\n");
        $this->stdout("Session window: {$settings->sessionWindow}s\n");

        return ExitCode::OK;
    }
}
