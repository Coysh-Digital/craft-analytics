<?php

namespace coyshdigital\craftanalytics\rollup;

use coyshdigital\craftanalytics\ingest\Hit;
use coyshdigital\craftanalytics\models\Goal;
use coyshdigital\craftanalytics\Plugin;
use coyshdigital\craftanalytics\services\GoalsService;
use coyshdigital\craftanalytics\session\Session;
use coyshdigital\craftanalytics\session\SessionDelta;

/**
 * Decides which goals a batch's hits converted, and which a closing session
 * converted at the end.
 *
 * Order matters and is preserved: handles are appended in the order the hits
 * that matched them happened, which is what lets a funnel ask "did they reach
 * step 2 *after* step 1" without storing a single path (see FunnelReporter).
 * Hits are sorted by timestamp first — concurrent workers and clock skew mean
 * spool order is nearly, but not reliably, time order, and "nearly" would show
 * up as funnel steps occasionally converting backwards.
 */
class GoalMatcher
{
    /** @var Goal[]|null */
    private ?array $liveGoals = null;

    public function __construct(
        /** Injected in tests; defaults to the plugin's service. */
        public ?GoalsService $goals = null,
        /** Goals are a Pro feature; on Lite this does nothing at all. */
        public ?bool $isPro = null,
    ) {
    }

    /**
     * Records this batch's goal conversions on the deltas.
     *
     * @param Hit[] $hits
     * @param array<string,SessionDelta> $deltas keyed "siteId:sessionKey"
     */
    public function matchBatch(array $hits, array $deltas): void
    {
        if (!$this->isPro() || $this->live() === []) {
            return;
        }

        $ordered = $hits;
        usort($ordered, static fn(Hit $a, Hit $b): int => $a->timestamp <=> $b->timestamp);

        foreach ($ordered as $hit) {
            $delta = $deltas[$hit->siteId . ':' . $hit->sessionKey] ?? null;

            if ($delta === null) {
                continue;
            }

            foreach ($this->live() as $goal) {
                if ($goal->matchesHit($hit)) {
                    $delta->matchGoal($goal->handle);
                }
            }
        }
    }

    /**
     * The full set of goals a finished session converted: those matched as its
     * hits arrived, plus the duration and scroll goals that could only be
     * judged now that it is over.
     *
     * @return Goal[]
     */
    public function conversions(Session $session): array
    {
        if (!$this->isPro()) {
            return [];
        }

        $converted = [];

        foreach ($session->goals as $handle) {
            $goal = $this->goalsService()->getByHandle($handle);

            // A goal deleted between the conversion and the session closing.
            // Nothing to attribute it to, so it is dropped rather than
            // counted against a name that no longer exists.
            if ($goal !== null && $goal->appliesTo($session->siteId)) {
                $converted[] = $goal;
            }
        }

        foreach ($this->goalsService()->enabledForSite($session->siteId) as $goal) {
            if (!$goal->isLive() && $goal->convertsAtClose($session)) {
                $converted[] = $goal;
            }
        }

        return $converted;
    }

    /**
     * @return Goal[] the goals decided per-hit
     */
    private function live(): array
    {
        return $this->liveGoals ??= array_values(array_filter(
            $this->goalsService()->all(),
            static fn(Goal $goal): bool => $goal->enabled && $goal->isLive(),
        ));
    }

    private function goalsService(): GoalsService
    {
        return $this->goals ??= Plugin::getInstance()->getGoals();
    }

    private function isPro(): bool
    {
        return $this->isPro ??= Plugin::getInstance()->is(Plugin::EDITION_PRO);
    }
}
