<?php

namespace coyshdigital\craftanalytics\models;

use coyshdigital\craftanalytics\session\Session;
use Craft;
use craft\base\Model;

/**
 * An ordered sequence of goals: "landed → added to basket → checked out".
 *
 * A funnel step *is* a goal rather than a second kind of matcher, so a step
 * and a goal can never disagree about what a conversion is.
 *
 * Funnels are measured within a session. There is no cross-session funnel for
 * Tier-1 visitors and there cannot be one: the salt rotates daily and the link
 * between two visits is destroyed by design (see docs/pro-analytics.md). A
 * three-day purchase decision is therefore three sessions, and this reports
 * what happened in each — it does not pretend to stitch them together.
 */
class Funnel extends Model
{
    public ?int $id = null;
    public string $uid = '';
    public string $name = '';
    public string $handle = '';
    public ?int $siteId = null;
    public bool $enabled = true;
    public int $sortOrder = 0;

    /**
     * Goal handles in order. Handles rather than IDs because that is what the
     * form posts and what a funnel means to a person; the table stores the ID
     * it resolves to, which is what the rollup joins against.
     *
     * @var array<int,string>
     */
    public array $steps = [];

    /**
     * How far this session got: 0 if it never reached step 1, otherwise the
     * position of the last step it completed **in order**.
     *
     * Order is enforced, not just membership. `Session::$goals` holds handles
     * in the order they converted, so a visitor who somehow hit the checkout
     * page before the basket page did not walk this funnel, and saying they
     * did would turn a broken flow into a healthy-looking one.
     *
     * @param array<string,Goal> $goalsByHandle every goal this funnel's steps
     *                                          could name
     */
    public function reachedStep(Session $session, array $goalsByHandle): int
    {
        $reached = 0;
        $searchFrom = 0;

        foreach ($this->steps as $handle) {
            $goal = $goalsByHandle[$handle] ?? null;

            // A step naming a goal that no longer exists cannot be satisfied,
            // and everything after it is unreachable. Guessing either way
            // would invent a number.
            if ($goal === null) {
                break;
            }

            // Duration and scroll are properties of the whole session rather
            // than things that happened at a point in it: "stayed 60 seconds"
            // is true of the visit, not true *at* some moment you could put in
            // a sequence. So they gate the step without consuming a position
            // in the order.
            //
            // Before this, they were looked for in the session's ordered goal
            // list - where they never appear, because that list is built while
            // the hits arrive and these two are only decided once the session
            // is over. Any funnel with a duration or scroll step therefore
            // died at that step and reported a completion rate of zero.
            if (!$goal->isLive()) {
                if (!$goal->convertsAtClose($session)) {
                    break;
                }

                $reached++;
                continue;
            }

            $at = array_search($handle, array_slice($session->goals, $searchFrom), true);

            if ($at === false) {
                break;
            }

            $searchFrom += (int)$at + 1;
            $reached++;
        }

        return $reached;
    }


    /**
     * @return array<int,mixed>
     */
    protected function defineRules(): array
    {
        return array_merge(parent::defineRules(), [
            [['name', 'handle'], 'required'],
            [['name'], 'string', 'max' => 255],
            [['handle'], 'string', 'max' => 64],
            [['handle'], 'match', 'pattern' => '/^[a-zA-Z][a-zA-Z0-9_]*$/', 'message' => Craft::t('craft-analytics', 'Handles must start with a letter and contain only letters, numbers and underscores.')],
            [['enabled'], 'boolean'],
            // skipOnEmpty must be off: an empty step list is precisely the
            // case this validator exists to reject, and Yii would otherwise
            // skip it and call a funnel with no steps valid.
            [['steps'], 'validateSteps', 'skipOnEmpty' => false],
        ]);
    }

    public function validateSteps(string $attribute): void
    {
        if (count($this->steps) < 2) {
            $this->addError($attribute, Craft::t('craft-analytics', 'A funnel needs at least two steps.'));

            return;
        }

        // The same goal twice would make the second step unreachable: its
        // first conversion consumes the match, and nothing after it can find
        // a later one.
        if (count(array_unique($this->steps)) !== count($this->steps)) {
            $this->addError($attribute, Craft::t('craft-analytics', 'Each step must be a different goal.'));
        }
    }
}
