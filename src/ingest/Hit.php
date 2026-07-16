<?php

namespace coyshdigital\craftanalytics\ingest;

use coyshdigital\craftanalytics\models\Campaign;

/**
 * One captured interaction — a pageview, or (Pro) an event, an outbound
 * click or a download — in transit between capture and the drain.
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
        /**
         * The consented visitor's stable ID (Pro), or null — which is the
         * case for everyone who has not affirmatively consented, i.e. almost
         * everyone, by design.
         */
        public readonly ?string $visitorId = null,
        /**
         * The Craft user ID, only when a consented visitor is signed in *and*
         * `associateUserId` is separately enabled.
         */
        public readonly ?int $userId = null,
        /**
         * The campaign this hit arrived with, if its URL was tagged.
         * Attributed to the session, not the pageview.
         */
        public readonly ?Campaign $campaign = null,
        /**
         * Country and region, resolved from the address at capture and
         * carried here in its place. The address itself never travels (C5).
         */
        public readonly string $countryCode = '',
        public readonly string $region = '',
        /** What happened: a pageview, or one of the Pro interaction kinds. */
        public readonly string $kind = self::KIND_VIEW,
        /** Pro: the custom event's name and optional monetary value. */
        public readonly ?string $eventName = null,
        public readonly ?float $eventValue = null,
        /** Pro: the URL an outbound click or download went to. */
        public readonly ?string $target = null,
        /** Pro: how far down the page they read, as a 25/50/75/100 bucket. */
        public readonly ?int $scrollBucket = null,
    ) {
    }

    public const KIND_VIEW = 'view';
    public const KIND_EVENT = 'event';
    public const KIND_OUTBOUND = 'outbound';
    public const KIND_DOWNLOAD = 'download';

    /**
     * A request from a crawler, counted only so that "where did my traffic
     * go" has an answer. Never a pageview, and never mixed into one.
     */
    public const KIND_CRAWLER = 'crawler';

    /** Whether this is a pageview rather than a Pro interaction. */
    public function isPageview(): bool
    {
        return $this->kind === self::KIND_VIEW;
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
            'vid' => $this->visitorId,
            'uid' => $this->userId,
            'cm' => $this->campaign?->toArray(),
            'cc' => $this->countryCode,
            'rg' => $this->region,
            // Only serialised when it isn't an ordinary pageview.
            'kd' => $this->kind === self::KIND_VIEW ? null : $this->kind,
            'en' => $this->eventName,
            'ev' => $this->eventValue,
            'tg' => $this->target,
            'sb' => $this->scrollBucket,
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
            visitorId: isset($data['vid']) ? (string)$data['vid'] : null,
            userId: isset($data['uid']) ? (int)$data['uid'] : null,
            campaign: is_array($data['cm'] ?? null) ? Campaign::fromArray($data['cm']) : null,
            countryCode: (string)($data['cc'] ?? ''),
            region: (string)($data['rg'] ?? ''),
            kind: (string)($data['kd'] ?? self::KIND_VIEW),
            eventName: isset($data['en']) ? (string)$data['en'] : null,
            eventValue: isset($data['ev']) ? (float)$data['ev'] : null,
            target: isset($data['tg']) ? (string)$data['tg'] : null,
            scrollBucket: isset($data['sb']) ? (int)$data['sb'] : null,
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
