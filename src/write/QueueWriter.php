<?php

namespace coyshdigital\craftanalytics\write;

use coyshdigital\craftanalytics\ingest\Hit;
use Craft;
use yii\base\Component;
use yii\queue\Queue;

/**
 * Pushes hits onto a dedicated analytics queue.
 *
 * Deliberately not Craft's queue: analytics must never delay a job someone is
 * waiting on, must never be run by Craft's web-request-triggered runner (which
 * would put the work back on a request thread, defeating C1), and must be
 * droppable under load without touching anything else. Requires a
 * `craftAnalyticsQueue` component and its own worker — see the docs.
 */
class QueueWriter extends Component implements WriterInterface
{
    public const COMPONENT_ID = 'craftAnalyticsQueue';

    public ?Queue $queue = null;

    public function write(Hit $hit): void
    {
        try {
            $this->queue()?->push(new TrackJob(['hit' => $hit->encode()]));
        } catch (\Throwable $e) {
            Craft::warning('Failed to queue analytics hit: ' . $e->getMessage(), __METHOD__);
        }
    }

    public static function isConfigured(): bool
    {
        return Craft::$app->has(self::COMPONENT_ID);
    }

    private function queue(): ?Queue
    {
        if ($this->queue !== null) {
            return $this->queue;
        }

        if (!self::isConfigured()) {
            Craft::warning(
                'craft-analytics: writeDriver is "queue" but no ' . self::COMPONENT_ID
                . ' component is configured; hits are being dropped.',
                __METHOD__,
            );

            return null;
        }

        $queue = Craft::$app->get(self::COMPONENT_ID);

        return $this->queue = $queue instanceof Queue ? $queue : null;
    }
}
