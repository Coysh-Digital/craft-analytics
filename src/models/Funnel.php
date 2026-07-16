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
     * Goal handles in order. Handles, not IDs, because project config must not
     * carry auto-increment IDs across environments.
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
     */
    public function reachedStep(Session $session): int
    {
        $reached = 0;
        $searchFrom = 0;

        foreach ($this->steps as $handle) {
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
     * @return array<string,mixed> the project config representation
     */
    public function toConfig(): array
    {
        return [
            'name' => $this->name,
            'handle' => $this->handle,
            'siteId' => $this->siteId,
            'enabled' => $this->enabled,
            'sortOrder' => $this->sortOrder,
            'steps' => array_values($this->steps),
        ];
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
