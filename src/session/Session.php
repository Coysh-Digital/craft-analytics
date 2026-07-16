<?php

namespace coyshdigital\craftanalytics\session;

/**
 * Live session state, held only in the hot layer (cache) — never a database
 * row (C6). When a session goes idle the drain folds these counters into the
 * rollups and deletes the record; nothing per-visitor survives.
 */
final class Session
{
    public function __construct(
        public int $siteId,
        public string $sessionKey,
        public string $visitorHash,
        public int $startedAt,
        public int $lastSeenAt,
        public int $pageviews,
        public string $entryPath,
        public string $lastPath,
        public string $referrer = '',
        public string $userAgent = '',
        /**
         * Set when the drain has decided this session is over and has staged
         * its counters for commit. Survives a crash so the same session can
         * never be counted twice (see DrainController).
         */
        public ?string $closedByBatch = null,
        /**
         * The last batch whose hits were applied here. Guards against a
         * re-run of the same batch (after a crash) counting its pageviews
         * twice.
         */
        public ?string $lastBatch = null,
        /**
         * Campaign touches, in order, for attribution when the session
         * closes. Almost always zero or one.
         *
         * @var array<int,array<string,string>>
         */
        public array $campaigns = [],
        /** ISO country code resolved at capture; the address is long gone. */
        public string $countryCode = '',
        public string $region = '',
        /**
         * Handles of the goals this session has already converted.
         *
         * Bounded by the number of goals configured, not by how much the
         * visitor browsed — the match happens as each hit arrives, so the
         * paths themselves are never kept (Goal::matchesHit()).
         *
         * @var array<int,string>
         */
        public array $goals = [],
        /** Deepest scroll bucket reached anywhere in the session, 0–100. */
        public int $maxScroll = 0,
    ) {
    }

    public function isBounce(): bool
    {
        return $this->pageviews <= 1;
    }

    public function durationMs(): int
    {
        return max(0, $this->lastSeenAt - $this->startedAt) * 1000;
    }

    /**
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        return [
            'si' => $this->siteId,
            'k' => $this->sessionKey,
            'v' => $this->visitorHash,
            's' => $this->startedAt,
            'l' => $this->lastSeenAt,
            'n' => $this->pageviews,
            'ep' => $this->entryPath,
            'lp' => $this->lastPath,
            'r' => $this->referrer,
            'ua' => $this->userAgent,
            'cb' => $this->closedByBatch,
            'lb' => $this->lastBatch,
            'cm' => $this->campaigns,
            'cc' => $this->countryCode,
            'rg' => $this->region,
            'g' => $this->goals,
            'ms' => $this->maxScroll,
        ];
    }

    /**
     * @param array<string,mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            siteId: (int)($data['si'] ?? 0),
            sessionKey: (string)($data['k'] ?? ''),
            visitorHash: (string)($data['v'] ?? ''),
            startedAt: (int)($data['s'] ?? 0),
            lastSeenAt: (int)($data['l'] ?? 0),
            pageviews: (int)($data['n'] ?? 0),
            entryPath: (string)($data['ep'] ?? ''),
            lastPath: (string)($data['lp'] ?? ''),
            referrer: (string)($data['r'] ?? ''),
            userAgent: (string)($data['ua'] ?? ''),
            closedByBatch: isset($data['cb']) ? (string)$data['cb'] : null,
            lastBatch: isset($data['lb']) ? (string)$data['lb'] : null,
            campaigns: is_array($data['cm'] ?? null) ? $data['cm'] : [],
            countryCode: (string)($data['cc'] ?? ''),
            region: (string)($data['rg'] ?? ''),
            goals: array_values(array_map(
                static fn($handle): string => (string)$handle,
                is_array($data['g'] ?? null) ? $data['g'] : [],
            )),
            maxScroll: (int)($data['ms'] ?? 0),
        );
    }
}
