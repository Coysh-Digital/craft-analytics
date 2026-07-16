<?php

namespace coyshdigital\craftanalytics\console\controllers;

use coyshdigital\craftanalytics\Plugin;
use craft\console\Controller;
use craft\helpers\Console;
use yii\console\ExitCode;

/**
 * Manages the rotating visitor-hash salt.
 *
 * Rotation is automatic (checked whenever a salt is used); this exists for
 * operators who want to force it — after a suspected leak, say.
 */
class SaltController extends Controller
{
    /**
     * Rotate the salt now, destroying the current one.
     *
     * Every anonymous visitor hash produced under the old salt becomes
     * permanently unlinkable to hashes produced after this, including for us.
     * In-flight sessions will be split.
     */
    public function actionRotate(): int
    {
        if (!$this->confirm('Rotate the visitor salt now? Open sessions will be split.', true)) {
            return ExitCode::OK;
        }

        $result = Plugin::getInstance()->getSalts()->rotate();

        $this->stdout("Salt rotated; the previous salt is destroyed.\n", Console::FG_GREEN);
        $this->stdout('Next rotation: ' . date('Y-m-d H:i:s T', $result['nextRotation']) . "\n");

        return ExitCode::OK;
    }
}
