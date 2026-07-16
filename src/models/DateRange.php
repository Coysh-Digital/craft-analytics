<?php

namespace coyshdigital\craftanalytics\models;

use Craft;

/**
 * A date range, in site time.
 *
 * Rollups are keyed by local date, so every range here is resolved in the
 * site's timezone rather than UTC — otherwise "today" would end at the wrong
 * hour for most of the world.
 */
final class DateRange
{
    public const PRESET_TODAY = 'today';
    public const PRESET_YESTERDAY = 'yesterday';
    public const PRESET_7_DAYS = '7d';
    public const PRESET_30_DAYS = '30d';
    public const PRESET_90_DAYS = '90d';
    public const PRESET_12_MONTHS = '12mo';

    private function __construct(
        public readonly string $from,
        public readonly string $to,
        public readonly string $preset,
        public readonly string $label,
    ) {
    }

    /**
     * @return array<string,string> preset => label, for the range picker
     */
    public static function presets(): array
    {
        return [
            self::PRESET_TODAY => Craft::t('craft-analytics', 'Today'),
            self::PRESET_YESTERDAY => Craft::t('craft-analytics', 'Yesterday'),
            self::PRESET_7_DAYS => Craft::t('craft-analytics', 'Last 7 days'),
            self::PRESET_30_DAYS => Craft::t('craft-analytics', 'Last 30 days'),
            self::PRESET_90_DAYS => Craft::t('craft-analytics', 'Last 90 days'),
            self::PRESET_12_MONTHS => Craft::t('craft-analytics', 'Last 12 months'),
        ];
    }

    public static function fromPreset(string $preset, ?int $now = null): self
    {
        $preset = isset(self::presets()[$preset]) ? $preset : self::PRESET_30_DAYS;
        $today = self::today($now);

        [$from, $to] = match ($preset) {
            self::PRESET_TODAY => [$today, $today],
            self::PRESET_YESTERDAY => [$today->modify('-1 day'), $today->modify('-1 day')],
            self::PRESET_7_DAYS => [$today->modify('-6 days'), $today],
            self::PRESET_90_DAYS => [$today->modify('-89 days'), $today],
            self::PRESET_12_MONTHS => [$today->modify('-1 year')->modify('+1 day'), $today],
            default => [$today->modify('-29 days'), $today],
        };

        return new self(
            $from->format('Y-m-d'),
            $to->format('Y-m-d'),
            $preset,
            self::presets()[$preset],
        );
    }

    /**
     * The range immediately before this one, of the same length — what a
     * comparison is measured against.
     */
    public function previous(): self
    {
        $from = new \DateTimeImmutable($this->from);
        $to = new \DateTimeImmutable($this->to);
        $days = (int)$from->diff($to)->format('%a') + 1;

        return new self(
            $from->modify("-$days days")->format('Y-m-d'),
            $to->modify("-$days days")->format('Y-m-d'),
            $this->preset,
            Craft::t('craft-analytics', 'Previous period'),
        );
    }

    public function days(): int
    {
        $from = new \DateTimeImmutable($this->from);
        $to = new \DateTimeImmutable($this->to);

        return (int)$from->diff($to)->format('%a') + 1;
    }

    /**
     * Whether to plot this range by hour rather than by day. Only a single
     * day is worth an hourly axis — and only recent days still have hourly
     * rows to plot (see Compactor).
     */
    public function isHourly(): bool
    {
        return $this->days() === 1;
    }

    /**
     * Every date in the range, so a chart can show days with no traffic as
     * zero rather than skipping them — a gap in a line implies missing data,
     * not a quiet Sunday.
     *
     * @return string[]
     */
    public function dates(): array
    {
        $dates = [];
        $cursor = new \DateTimeImmutable($this->from);
        $end = new \DateTimeImmutable($this->to);

        while ($cursor <= $end) {
            $dates[] = $cursor->format('Y-m-d');
            $cursor = $cursor->modify('+1 day');
        }

        return $dates;
    }

    private static function today(?int $now): \DateTimeImmutable
    {
        $timestamp = $now ?? time();

        return (new \DateTimeImmutable('@' . $timestamp))
            ->setTimezone(new \DateTimeZone(Craft::$app->getTimeZone()))
            ->setTime(0, 0);
    }
}
