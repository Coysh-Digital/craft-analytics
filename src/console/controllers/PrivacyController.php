<?php

namespace coyshdigital\craftanalytics\console\controllers;

use coyshdigital\craftanalytics\Plugin;
use craft\console\Controller;
use craft\helpers\Console;
use craft\helpers\FileHelper;
use yii\console\ExitCode;

/**
 * Data-subject rights, and the compliance paperwork.
 *
 *     craft-analytics/privacy/export --visitor-id=abc123
 *     craft-analytics/privacy/export --user-id=42
 *     craft-analytics/privacy/erase --visitor-id=abc123
 *     craft-analytics/privacy/document --to=./docs/privacy
 */
class PrivacyController extends Controller
{
    /** The consented visitor's ID (the value of the `_ca_vid` cookie). */
    public ?string $visitorId = null;

    /** A Craft user ID, for sites that link analytics to accounts. */
    public ?int $userId = null;

    /** Where `document` writes its Markdown. Prints to stdout when unset. */
    public ?string $to = null;

    /**
     * Also erase the consent evidence. Off by default — see actionErase().
     */
    public bool $includeConsentLog = false;

    public function options($actionID): array
    {
        return match ($actionID) {
            'export' => array_merge(parent::options($actionID), ['visitorId', 'userId', 'to']),
            'erase' => array_merge(parent::options($actionID), ['visitorId', 'userId', 'includeConsentLog']),
            'document' => array_merge(parent::options($actionID), ['to']),
            default => parent::options($actionID),
        };
    }

    /**
     * Export everything held about one data subject (GDPR Art. 15).
     */
    public function actionExport(): int
    {
        if (!$this->hasSubject()) {
            return ExitCode::USAGE;
        }

        $data = Plugin::getInstance()->getPrivacy()->export($this->visitorId, $this->userId);
        $json = (string)json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        if ($this->to !== null) {
            FileHelper::writeToFile($this->to, $json);
            $this->stdout("Written to {$this->to}\n", Console::FG_GREEN);
        } else {
            $this->stdout($json . "\n");
        }

        $this->stdout(sprintf(
            "\n%d journey row(s), %d consent record(s).\n",
            count($data['journeys']),
            count($data['consent']),
        ));

        // The thing people are surprised by, said out loud.
        $this->stdout(
            "Aggregate statistics are not included: they are anonymous counts and sketches\n"
            . "containing no personal data, from which no individual can be extracted.\n",
            Console::FG_GREY,
        );

        return ExitCode::OK;
    }

    /**
     * Erase a data subject (GDPR Art. 17).
     */
    public function actionErase(): int
    {
        if (!$this->hasSubject()) {
            return ExitCode::USAGE;
        }

        $subject = $this->visitorId !== null ? "visitor {$this->visitorId}" : "user {$this->userId}";

        if (!$this->confirm("Permanently erase the analytics records for $subject?", false)) {
            return ExitCode::OK;
        }

        $result = Plugin::getInstance()->getPrivacy()->erase(
            $this->visitorId,
            $this->userId,
            $this->includeConsentLog,
        );

        $this->stdout(sprintf(
            "Erased %d journey row(s) and %d consent record(s).\n",
            $result['journeys'],
            $result['consentLog'],
        ), Console::FG_GREEN);

        if (!$this->includeConsentLog) {
            $this->stdout(
                "The consent record was kept: it is the evidence that the erased processing was\n"
                . "lawful. Pass --include-consent-log to remove it too.\n",
                Console::FG_GREY,
            );
        }

        $this->stdout(
            "Aggregate statistics are unaffected — there is no individual in them to erase.\n",
            Console::FG_GREY,
        );

        return ExitCode::OK;
    }

    /**
     * Generate the ROPA entry, privacy-notice appendix and DPIA summary from
     * the live configuration.
     */
    public function actionDocument(): int
    {
        $documents = Plugin::getInstance()->getPrivacyDocuments()->all();

        if ($this->to === null) {
            foreach ($documents as $name => $markdown) {
                $this->stdout("\n" . str_repeat('=', 72) . "\n$name\n" . str_repeat('=', 72) . "\n\n");
                $this->stdout($markdown);
            }

            return ExitCode::OK;
        }

        foreach ($documents as $name => $markdown) {
            $path = rtrim($this->to, '/') . '/' . $name;
            FileHelper::writeToFile($path, $markdown);
            $this->stdout("Written $path\n", Console::FG_GREEN);
        }

        $this->stdout(
            "\nThese are drafts generated from your configuration. They are accurate about what\n"
            . "the software does; they are not legal advice. Have somebody qualified review them.\n",
            Console::FG_YELLOW,
        );

        return ExitCode::OK;
    }

    private function hasSubject(): bool
    {
        if ($this->visitorId === null && $this->userId === null) {
            $this->stderr("Specify --visitor-id or --user-id.\n", Console::FG_RED);

            return false;
        }

        return true;
    }
}
