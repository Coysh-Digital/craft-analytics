<?php

namespace coyshdigital\craftanalytics\ingest;

/**
 * One captured pageview, in transit between capture and the drain.
 *
 * Deliberately not an ActiveRecord and never persisted as-is: hits exist in
 * the spool for seconds, are folded into rollups by the drain, and are then
 * gone (C6). Serialised with short keys because the spool is written once per
 * pageview and every byte is I/O on the request thread.
 *
 * Note what is absent: no IP address, in any form (C5). The visitor hash is
 * computed at capture time and the address is discarded with the call frame.
 */
final class Hit
{
    public function __construct(
        public readonly int $siteId,
        public readonly string $path,
        public readonly string $visitorHash,
        public readonly string $sessionKey,
        public readonly int $timestamp,
        public readonly ?int $elementId = null,
        public readonly string $referrer = '',
        public readonly string $userAgent = '',
        public readonly string $acceptLanguage = '',
        /**
         * Time on page, from the beacon. Zero for server-side capture, which
         * has no way of knowing.
         */
        public readonly int $dwellMs = 0,
        /**
         * Whether this hit is a pageview to be counted.
         *
         * False when a beacon is only reporting dwell for a view the server
         * already counted — the hybrid-mode dedupe (see BeaconController).
         */
        public readonly bool $countView = true,
    ) {
    }

    /**
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        return array_filter([
            'si' => $this->siteId,
            'p' => $this->path,
            'v' => $this->visitorHash,
            'k' => $this->sessionKey,
            't' => $this->timestamp,
            'e' => $this->elementId,
            'r' => $this->referrer,
            'ua' => $this->userAgent,
            'al' => $this->acceptLanguage,
            'd' => $this->dwellMs,
            // Only serialised when false, which is the exception.
            'nv' => $this->countView ? null : 1,
        ], static fn($value) => $value !== null && $value !== '' && $value !== 0);
    }

    /**
     * @param array<string,mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            siteId: (int)($data['si'] ?? 0),
            path: (string)($data['p'] ?? ''),
            visitorHash: (string)($data['v'] ?? ''),
            sessionKey: (string)($data['k'] ?? ''),
            timestamp: (int)($data['t'] ?? 0),
            elementId: isset($data['e']) ? (int)$data['e'] : null,
            referrer: (string)($data['r'] ?? ''),
            userAgent: (string)($data['ua'] ?? ''),
            acceptLanguage: (string)($data['al'] ?? ''),
            dwellMs: (int)($data['d'] ?? 0),
            countView: !isset($data['nv']),
        );
    }

    public function encode(): string
    {
        return json_encode($this->toArray(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
            ?: throw new \RuntimeException('Failed to encode hit.');
    }

    public static function decode(string $line): ?self
    {
        $data = json_decode($line, true);

        return is_array($data) ? self::fromArray($data) : null;
    }
}
