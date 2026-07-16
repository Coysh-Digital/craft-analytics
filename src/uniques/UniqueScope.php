<?php

namespace coyshdigital\craftanalytics\uniques;

/**
 * Identifies one counter: "distinct visitors of X, on this site, on this
 * date/hour".
 */
final class UniqueScope
{
    public const KIND_PAGE = 'p';
    public const KIND_SESSION = 's';

    public const HOUR_DAILY = -1;

    public function __construct(
        public readonly string $kind,
        public readonly int $siteId,
        public readonly string $date,
        public readonly int $hour = self::HOUR_DAILY,
        public readonly ?int $dimId = null,
    ) {
    }

    /**
     * Stable identity for drivers that key their own storage (Redis, exact).
     */
    public function key(): string
    {
        return implode(':', [$this->kind, $this->siteId, $this->date, $this->hour, $this->dimId ?? 0]);
    }
}
