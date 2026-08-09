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

    /**
     * The discriminator for an absolute range.
     *
     * Deliberately **not** a key in presets(). That array is the whitelist for
     * the scheduled report period (Settings), the Overview widget's range, and
     * the report console command; adding 'custom' there would make it a
     * saveable value for a recurring email or a pinned widget, where an
     * absolute window silently freezes and reports the same figures forever.
     * Keeping it a bare constant is what lets all three validators stay as
     * they are.
     */
    public const PRESET_CUSTOM = 'custom';

    /**
     * The widest custom window.
     *
     * Just above the 365 days the 12mo preset already produces, so a custom
     * range can never be a heavier query than something the picker already
     * offers. The bound is real rather than decorative: StatsService::trend()
     * issues one uniques query per day in the range, and the from/to are
     * attacker-supplied through a query string.
     */
    public const MAX_CUSTOM_DAYS = 400;

    private function __construct(
        public readonly string $from,
        public readonly string $to,
        public readonly string $preset,
        public readonly string $param,
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

    /**
     * A preset, and only a preset.
     *
     * Unchanged, and it must stay that way: the callers that may only ever
     * hold a preset - the scheduled report, the Overview widget - go through
     * here, so being unable to parse a custom token is the property that keeps
     * them safe rather than an omission.
     */
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
            $preset,
            self::presets()[$preset],
        );
    }

    /**
     * A preset handle or an absolute `YYYY-MM-DD:YYYY-MM-DD` token.
     *
     * This is what reads a URL, a GraphQL argument or a Twig call. Anything
     * unrecognised falls back to 30 days, exactly as fromPreset() does - the
     * range is chosen by whoever can write a query string, so it must never be
     * able to fail loudly or expensively.
     */
    public static function fromParam(string $value, ?int $now = null): self
    {
        $dates = self::parseToken($value);

        return $dates === null
            ? self::fromPreset($value, $now)
            : self::custom($dates[0], $dates[1], $now);
    }

    /**
     * An absolute range.
     *
     * Malformed input falls back to 30 days; input that is merely unreasonable
     * is clamped, because somebody who asks for a window ending next year meant
     * something, and 30 days is not it.
     */
    public static function custom(string $from, string $to, ?int $now = null): self
    {
        $start = self::parseDate($from);
        $end = self::parseDate($to);

        if ($start === null || $end === null) {
            return self::fromPreset(self::PRESET_30_DAYS, $now);
        }

        // Picked the wrong way round. There is nothing to protect by refusing,
        // and swapping gives them what they meant.
        if ($start > $end) {
            [$start, $end] = [$end, $start];
        }

        // A range ending in 2099 makes dates() emit tens of thousands of labels
        // and trend() run a query for each of them.
        $today = self::today($now);

        if ($end > $today) {
            $end = $today;
        }

        $earliest = $end->modify('-' . (self::MAX_CUSTOM_DAYS - 1) . ' days');

        if ($start < $earliest) {
            $start = $earliest;
        }

        // No lower bound beyond the span: a window in 1990 is harmless, it just
        // returns nothing. Clamping to rollupRetentionMonths would mean reading
        // the plugin settings from inside a model that has no service
        // dependencies at all, which is worth more than the tidiness.
        return new self(
            $start->format('Y-m-d'),
            $end->format('Y-m-d'),
            self::PRESET_CUSTOM,
            $start->format('Y-m-d') . ':' . $end->format('Y-m-d'),
            Craft::t('craft-analytics', '{from} to {to}', [
                'from' => $start->format('j M Y'),
                'to' => $end->format('j M Y'),
            ]),
        );
    }

    /**
     * Whether a string names a range, for the callers that should complain
     * rather than quietly fall back - the console command, where a mistyped
     * date is a mistake worth reporting.
     */
    public static function isValidParam(string $value): bool
    {
        if (isset(self::presets()[$value])) {
            return true;
        }

        $dates = self::parseToken($value);

        return $dates !== null;
    }

    public function isCustom(): bool
    {
        return $this->preset === self::PRESET_CUSTOM;
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

        $start = $from->modify("-$days days");
        $end = $to->modify("-$days days");

        // The preset is kept as provenance, but the param is recomputed: a
        // shifted window is not what its preset handle describes, so linking to
        // "7d" here would send you to the current seven days rather than the
        // seven this range compares against.
        return new self(
            $start->format('Y-m-d'),
            $end->format('Y-m-d'),
            $this->preset,
            $start->format('Y-m-d') . ':' . $end->format('Y-m-d'),
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

    /**
     * The two dates in a `YYYY-MM-DD:YYYY-MM-DD` token, or null if it is not
     * one - including when it is shaped like one but names a date that does
     * not exist.
     *
     * @return array{0:string,1:string}|null
     */
    private static function parseToken(string $value): ?array
    {
        $parts = explode(':', $value);

        if (count($parts) !== 2) {
            return null;
        }

        if (self::parseDate($parts[0]) === null || self::parseDate($parts[1]) === null) {
            return null;
        }

        return [$parts[0], $parts[1]];
    }

    /**
     * A `YYYY-MM-DD` date, at midnight in site time.
     *
     * The round trip through format() is the point: createFromFormat() happily
     * rolls 2026-02-30 forward to 3 March and 2026-13-01 into 2027, so parsing
     * alone would accept dates nobody meant and report figures for the wrong
     * month. The `!` resets the time fields, which otherwise inherit the
     * current clock.
     */
    private static function parseDate(string $value): ?\DateTimeImmutable
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
            return null;
        }

        $date = \DateTimeImmutable::createFromFormat(
            '!Y-m-d',
            $value,
            new \DateTimeZone(Craft::$app->getTimeZone()),
        );

        if ($date === false || $date->format('Y-m-d') !== $value) {
            return null;
        }

        return $date;
    }

    /**
     * Whether this range reaches today.
     *
     * The dividing line for caching a report: a range that ends before today
     * describes rollup rows nothing is still writing to, so the same question
     * has the same answer until retention or a late batch changes it. A range
     * that includes today is being added to as it is read.
     */
    public function includesToday(?int $now = null): bool
    {
        return $this->to >= self::today($now)->format('Y-m-d');
    }

    private static function today(?int $now): \DateTimeImmutable
    {
        $timestamp = $now ?? time();

        return (new \DateTimeImmutable('@' . $timestamp))
            ->setTimezone(new \DateTimeZone(Craft::$app->getTimeZone()))
            ->setTime(0, 0);
    }
}
