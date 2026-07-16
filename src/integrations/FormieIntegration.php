<?php

namespace coyshdigital\craftanalytics\integrations;

use coyshdigital\craftanalytics\Plugin;
use Craft;
use yii\base\Event;

/**
 * Counts Formie submissions as events.
 *
 * Wired only when Formie is actually installed, and by class name through
 * Craft's own event system, so this file never requires Formie to exist. The
 * plugin does not depend on Formie; it notices it.
 *
 * A submission is an event named `form:<handle>`, so a goal can be defined
 * against it like any other event, and a form can be a funnel step.
 */
class FormieIntegration
{
    public const EVENT_CLASS = 'verbb\formie\services\Submissions';
    public const EVENT_NAME = 'afterSubmissionRequest';

    public static function isAvailable(): bool
    {
        return Craft::$app->getPlugins()->isPluginEnabled('formie');
    }

    public static function attach(): void
    {
        if (!self::isAvailable()) {
            return;
        }

        Event::on(
            self::EVENT_CLASS,
            self::EVENT_NAME,
            static function(Event $event) {
                self::handle($event);
            },
        );
    }

    /**
     * Records a completed submission.
     *
     * Only successful ones: a failed validation is not a conversion, and
     * counting it would make every form look like it works.
     */
    private static function handle(Event $event): void
    {
        /** @var object{submission: object}|Event $event */
        $submission = $event->submission ?? null;

        if ($submission === null) {
            return;
        }

        // Formie sets this once the submission is actually saved and the
        // spam checks have passed.
        if (property_exists($submission, 'isSpam') && $submission->isSpam) {
            return;
        }

        $status = $submission->getStatus() ?? null;

        if ($status !== null && property_exists($status, 'handle') && $status->handle === 'spam') {
            return;
        }

        $form = $submission->getForm();

        if ($form === null) {
            return;
        }

        Plugin::getInstance()->getCapture()->trackEvent(
            'form:' . $form->handle,
            null,
            // The page the form was on, which is what a report wants, rather
            // than Formie's action URL.
            self::submittedFromPath(),
        );
    }

    /**
     * The page the form was submitted from.
     *
     * A submission is a POST to an action URL, so the referrer is the only
     * record of where the visitor actually was.
     */
    private static function submittedFromPath(): ?string
    {
        $request = Craft::$app->getRequest();

        if (!$request instanceof \craft\web\Request) {
            return null;
        }

        $referrer = $request->getReferrer();

        if ($referrer === null) {
            return null;
        }

        $path = parse_url($referrer, PHP_URL_PATH);

        return is_string($path) ? $path : null;
    }
}
