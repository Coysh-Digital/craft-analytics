<?php

namespace coyshdigital\craftanalytics\models;

use coyshdigital\craftanalytics\enums\GoalType;
use coyshdigital\craftanalytics\ingest\Hit;
use coyshdigital\craftanalytics\session\Session;
use Craft;
use craft\base\Model;
use craft\helpers\StringHelper;

/**
 * Something worth counting.
 *
 * Goals live in project config, so they deploy with the rest of the site
 * rather than being retyped into production by hand.
 *
 * A goal converts **once per session**, not once per pageview: somebody who
 * reloads the thank-you page three times converted once, and counting three
 * would be flattering and wrong.
 *
 * Evaluation is split in two, and the split is the whole reason this costs no
 * storage:
 *
 * - {@see matchesHit()} — page, event and entry goals are decided the moment
 *   the hit arrives, and the session records only the *handles that matched*.
 *   Session state is therefore bounded by the number of goals (a handful),
 *   not by the number of pages viewed. Storing every path a session touched
 *   so it could be matched later would make the hot layer grow with traffic,
 *   which is exactly what C2 forbids.
 * - {@see convertsAtClose()} — duration and scroll can only be known when the
 *   session ends, and both are already single scalars on it.
 */
class Goal extends Model
{
    public ?int $id = null;
    public string $uid = '';
    public string $name = '';
    public string $handle = '';
    public string $type = GoalType::Url->value;

    /** What to match: a path pattern, event name, entry ID, seconds, or a percentage. */
    public string $target = '';

    /**
     * What one conversion is worth. Optional — plenty of goals are worth
     * knowing about without being worth money.
     */
    public float $value = 0.0;

    public bool $enabled = true;

    /** Null means every site. */
    public ?int $siteId = null;

    public int $sortOrder = 0;

    public function goalType(): GoalType
    {
        return GoalType::tryFrom($this->type) ?? GoalType::Url;
    }

    /** Whether this goal is decided per-hit rather than at session close. */
    public function isLive(): bool
    {
        return in_array($this->goalType(), [GoalType::Url, GoalType::Event, GoalType::Element], true);
    }

    public function appliesTo(int $siteId): bool
    {
        return $this->enabled && ($this->siteId === null || $this->siteId === $siteId);
    }

    /**
     * Whether this single hit converts the goal.
     */
    public function matchesHit(Hit $hit): bool
    {
        if (!$this->appliesTo($hit->siteId)) {
            return false;
        }

        return match ($this->goalType()) {
            GoalType::Url => $hit->isPageview() && $this->matchesPath($hit->path),
            GoalType::Event => $hit->kind === Hit::KIND_EVENT && $hit->eventName === $this->target,
            GoalType::Element => $hit->isPageview() && $hit->elementId === (int)$this->target,
            default => false,
        };
    }

    /**
     * Whether a finished session converts a goal that could only be judged at
     * the end.
     */
    public function convertsAtClose(Session $session): bool
    {
        if (!$this->appliesTo($session->siteId)) {
            return false;
        }

        return match ($this->goalType()) {
            GoalType::Duration => $session->durationMs() >= (int)$this->target * 1000,
            GoalType::Scroll => $session->maxScroll >= (int)$this->target,
            default => false,
        };
    }

    /**
     * Path matching with `*` wildcards.
     *
     * Matched against the path with its query string stripped: /thank-you and
     * /thank-you?ref=email are the same page, and a goal that counted only the
     * first would be quietly, unhelpfully wrong.
     */
    private function matchesPath(string $path): bool
    {
        return fnmatch($this->target, explode('?', $path, 2)[0]);
    }

    /**
     * @return array<string,mixed> the project config representation
     */
    public function toConfig(): array
    {
        return [
            'name' => $this->name,
            'handle' => $this->handle,
            'type' => $this->type,
            'target' => $this->target,
            'value' => $this->value,
            'enabled' => $this->enabled,
            'siteId' => $this->siteId,
            'sortOrder' => $this->sortOrder,
        ];
    }

    /**
     * @return array<int,mixed>
     */
    protected function defineRules(): array
    {
        return array_merge(parent::defineRules(), [
            [['name', 'handle', 'type', 'target'], 'required'],
            [['name'], 'string', 'max' => 255],
            [['handle'], 'string', 'max' => 64],
            [['handle'], 'match', 'pattern' => '/^[a-zA-Z][a-zA-Z0-9_]*$/', 'message' => Craft::t('craft-analytics', 'Handles must start with a letter and contain only letters, numbers and underscores.')],
            [['type'], 'in', 'range' => array_map(static fn(GoalType $t) => $t->value, GoalType::cases())],
            [['value'], 'number', 'min' => 0],
            [['enabled'], 'boolean'],
            [['target'], 'validateTarget'],
        ]);
    }

    /**
     * A target that cannot mean anything for its type is a goal that will
     * never convert. Better to say so at the form than to leave somebody
     * wondering for a month why the number is still zero.
     */
    public function validateTarget(string $attribute): void
    {
        $value = trim($this->target);

        match ($this->goalType()) {
            GoalType::Url => !StringHelper::startsWith($value, '/')
                ? $this->addError($attribute, Craft::t('craft-analytics', 'A page goal’s target must start with /.'))
                : null,
            GoalType::Element, GoalType::Duration => !ctype_digit($value) || (int)$value < 1
                ? $this->addError($attribute, Craft::t('craft-analytics', 'This target must be a positive number.'))
                : null,
            GoalType::Scroll => !in_array($value, ['25', '50', '75', '100'], true)
                ? $this->addError($attribute, Craft::t('craft-analytics', 'Scroll depth must be 25, 50, 75 or 100.'))
                : null,
            GoalType::Event => $value === ''
                ? $this->addError($attribute, Craft::t('craft-analytics', 'Name the event this goal listens for.'))
                : null,
        };
    }
}
