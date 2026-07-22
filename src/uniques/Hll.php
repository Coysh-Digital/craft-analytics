<?php

namespace coyshdigital\craftanalytics\uniques;

/**
 * A HyperLogLog sketch: counts distinct visitors in a fixed, tiny amount of
 * space, and — the part that matters here — merges.
 *
 * Why this exists at all: "unique visitors over the last 30 days" cannot be
 * computed by summing daily uniques (that counts a returning visitor once per
 * day). Summing is what most tools do and it is simply wrong. A mergeable
 * sketch lets a range be answered by unioning the sketches of its parts, so
 * the same visitor collapses to one — no raw rows required (C2, C6).
 *
 * Original implementation, written from the published papers:
 *   - Flajolet, Fusy, Gandouet & Meunier, "HyperLogLog: the analysis of a
 *     near-optimal cardinality estimation algorithm" (2007) — the estimator,
 *     the alpha constants and the small-range correction.
 *   - Heule, Nunkesser & Hall, "HyperLogLog in Practice" (2013) — the idea of
 *     a sparse representation for low-cardinality sketches.
 * No third-party HLL code was consulted; see docs/attribution.md.
 *
 * ## Representations
 *
 * **Sparse** — a map of touched register index => rank. What almost every
 * bucket is: a page with 40 visitors in an hour costs ~120 bytes rather than
 * a full register array. Promoted to dense once it stops being the cheaper
 * encoding.
 *
 * **Dense** — one byte per register (m bytes). Deliberately not the 6-bit
 * packing from the paper: at these precisions the saving is ~25% of an
 * already-small blob, and the sparse mode is what keeps real tables small.
 * Simpler is worth more here than 4 KB → 3 KB.
 *
 * ## Accuracy
 *
 * Standard error is 1.04/sqrt(m): ±1.6% at p=12 (4 KB dense), ±0.8% at p=14
 * (16 KB). Small cardinalities use linear counting, which is near-exact.
 */
final class Hll
{
    /** Serialisation format version — bump if the layout ever changes. */
    public const VERSION = 1;

    public const FORMAT_SPARSE = 0;
    public const FORMAT_DENSE = 1;

    public const MIN_PRECISION = 11;
    public const MAX_PRECISION = 14;
    public const DEFAULT_PRECISION = 12;

    /** Bytes per sparse entry: uint16 index + uint8 rank. */
    private const SPARSE_ENTRY_BYTES = 3;

    private const HEADER_BYTES = 3;

    /** Number of registers. */
    private readonly int $m;

    /** @var array<int,int>|null register index => rank, when sparse */
    private ?array $sparse = [];

    /** One byte per register, when dense. */
    private ?string $dense = null;

    public function __construct(
        private readonly int $precision = self::DEFAULT_PRECISION,
    ) {
        if ($precision < self::MIN_PRECISION || $precision > self::MAX_PRECISION) {
            throw new \InvalidArgumentException(
                "HLL precision must be between " . self::MIN_PRECISION . ' and ' . self::MAX_PRECISION . ".",
            );
        }

        $this->m = 1 << $precision;
    }

    public function precision(): int
    {
        return $this->precision;
    }

    public function isSparse(): bool
    {
        return $this->sparse !== null;
    }

    /**
     * Adds a visitor hash.
     *
     * The input is already a uniformly distributed 64-bit digest (the
     * truncated, salted visitor hash), so it is used directly as the HLL hash
     * — re-hashing it would buy nothing.
     */
    public function add(string $visitorHash): void
    {
        if (strlen($visitorHash) !== 16 || !ctype_xdigit($visitorHash)) {
            throw new \InvalidArgumentException('Visitor hash must be 16 hex characters (8 bytes).');
        }

        $bytes = (string)hex2bin($visitorHash);

        /** @var array{1: int, 2: int} $parts */
        $parts = unpack('N2', $bytes);
        $hi = $parts[1];
        $lo = $parts[2];

        $index = $hi >> (32 - $this->precision);
        $rank = self::rank($hi, $lo, $this->precision);

        $this->setRegister($index, $rank);
    }

    /**
     * Unions another sketch into this one.
     *
     * This is the whole point: merge(A, B) is exactly the sketch of A ∪ B, so
     * a month's uniques is the union of its days rather than their sum, and
     * hourly rows can be compacted into daily ones without losing anything.
     */
    public function merge(self $other): void
    {
        if ($other->precision !== $this->precision) {
            throw new \InvalidArgumentException('Cannot merge sketches of different precisions.');
        }

        if ($other->sparse !== null) {
            foreach ($other->sparse as $index => $rank) {
                $this->setRegister($index, $rank);
            }

            return;
        }

        $this->toDense();
        $otherDense = (string)$other->dense;
        $dense = (string)$this->dense;

        for ($i = 0; $i < $this->m; $i++) {
            $rank = ord($otherDense[$i]);

            if ($rank > ord($dense[$i])) {
                $dense[$i] = chr($rank);
            }
        }

        $this->dense = $dense;
    }

    /**
     * Estimated number of distinct visitors.
     */
    public function count(): int
    {
        $sum = 0.0;
        $zeroRegisters = 0;

        if ($this->sparse !== null) {
            $zeroRegisters = $this->m - count($this->sparse);
            $sum = (float)$zeroRegisters;

            foreach ($this->sparse as $rank) {
                $sum += 2 ** -$rank;
            }
        } else {
            $dense = (string)$this->dense;

            for ($i = 0; $i < $this->m; $i++) {
                $rank = ord($dense[$i]);

                if ($rank === 0) {
                    $zeroRegisters++;
                    $sum += 1.0;
                } else {
                    $sum += 2 ** -$rank;
                }
            }
        }

        $estimate = self::alpha($this->m) * $this->m ** 2 / $sum;

        // Below ~2.5m the raw estimator is badly biased; linear counting over
        // the untouched registers is near-exact there. No large-range
        // correction is needed: the 64-bit input makes hash collisions
        // irrelevant at any cardinality a website will produce.
        if ($estimate <= 2.5 * $this->m && $zeroRegisters > 0) {
            return (int)round($this->m * log($this->m / $zeroRegisters));
        }

        return (int)round($estimate);
    }

    /**
     * Binary form for storage on a rollup row.
     *
     * Layout: version | precision | format, then either packed sparse entries
     * or the raw register array. The header means an old blob is still
     * readable after a format change.
     */
    public function serialize(): string
    {
        if ($this->sparse !== null) {
            $payload = '';
            $sparse = $this->sparse;
            ksort($sparse);

            foreach ($sparse as $index => $rank) {
                $payload .= pack('nC', $index, $rank);
            }

            return pack('CCC', self::VERSION, $this->precision, self::FORMAT_SPARSE) . $payload;
        }

        return pack('CCC', self::VERSION, $this->precision, self::FORMAT_DENSE) . (string)$this->dense;
    }

    public static function deserialize(string $blob): self
    {
        if (strlen($blob) < self::HEADER_BYTES) {
            throw new \InvalidArgumentException('Sketch blob is too short to contain a header.');
        }

        /** @var array{version: int, precision: int, format: int} $header */
        $header = unpack('Cversion/Cprecision/Cformat', $blob);

        if ($header['version'] !== self::VERSION) {
            throw new \InvalidArgumentException("Unsupported sketch version {$header['version']}.");
        }

        $sketch = new self($header['precision']);
        $payload = substr($blob, self::HEADER_BYTES);

        if ($header['format'] === self::FORMAT_DENSE) {
            if (strlen($payload) !== $sketch->m) {
                throw new \InvalidArgumentException('Dense sketch payload does not match its precision.');
            }

            $sketch->sparse = null;
            $sketch->dense = $payload;

            return $sketch;
        }

        if (strlen($payload) % self::SPARSE_ENTRY_BYTES !== 0) {
            throw new \InvalidArgumentException('Sparse sketch payload is truncated.');
        }

        $entries = strlen($payload) / self::SPARSE_ENTRY_BYTES;

        for ($i = 0; $i < $entries; $i++) {
            /** @var array{1: int, 2: int} $entry */
            $entry = unpack('nindex/Crank', substr($payload, $i * self::SPARSE_ENTRY_BYTES, self::SPARSE_ENTRY_BYTES));
            /** @var array{index: int, rank: int} $entry */
            $sketch->sparse[$entry['index']] = $entry['rank'];
        }

        return $sketch;
    }

    /**
     * Merges a set of serialised sketches — how a date range's uniques are
     * answered.
     *
     * Skips what it cannot read rather than throwing. A range spans many rows
     * and one of them may be corrupt, truncated, or — much more likely — left
     * at a precision the operator has since changed away from. Refusing the
     * whole range because one row of it is unreadable would take out every
     * unique figure in the reports, and the nightly compaction with them, for
     * a fault that costs one bucket. `$onUnreadable` is how the caller says
     * so out loud.
     *
     * @param iterable<string|null> $blobs
     * @param ?callable(\InvalidArgumentException):void $onUnreadable
     */
    public static function mergeAll(
        iterable $blobs,
        int $precision = self::DEFAULT_PRECISION,
        ?callable $onUnreadable = null,
    ): self {
        $merged = new self($precision);

        foreach ($blobs as $blob) {
            if ($blob === null || $blob === '') {
                continue;
            }

            try {
                // Both steps can reject the blob: deserialize on a damaged
                // header or payload, merge on a precision that no longer
                // matches the configured one.
                $merged->merge(self::deserialize($blob));
            } catch (\InvalidArgumentException $e) {
                if ($onUnreadable !== null) {
                    $onUnreadable($e);
                }
            }
        }

        return $merged;
    }

    public function byteSize(): int
    {
        return strlen($this->serialize());
    }

    private function setRegister(int $index, int $rank): void
    {
        if ($this->sparse !== null) {
            if (($this->sparse[$index] ?? 0) < $rank) {
                $this->sparse[$index] = $rank;
            }

            // Once the sparse map costs more than the register array would,
            // it has stopped earning its keep.
            if (count($this->sparse) * self::SPARSE_ENTRY_BYTES >= $this->m) {
                $this->toDense();
            }

            return;
        }

        $dense = (string)$this->dense;

        if (ord($dense[$index]) < $rank) {
            $dense[$index] = chr($rank);
            $this->dense = $dense;
        }
    }

    private function toDense(): void
    {
        if ($this->sparse === null) {
            return;
        }

        $dense = str_repeat("\0", $this->m);

        foreach ($this->sparse as $index => $rank) {
            $dense[$index] = chr($rank);
        }

        $this->dense = $dense;
        $this->sparse = null;
    }

    /**
     * Position of the first 1 bit after the index bits, 1-based.
     *
     * Split across the two 32-bit halves because PHP's arithmetic shift on a
     * negative int would corrupt a full 64-bit value.
     */
    private static function rank(int $hi, int $lo, int $precision): int
    {
        $rank = 1;
        $remainingHiBits = 32 - $precision;
        $word = ($hi << $precision) & 0xFFFFFFFF;

        for ($i = 0; $i < $remainingHiBits; $i++) {
            if (($word & 0x80000000) !== 0) {
                return $rank;
            }

            $rank++;
            $word = ($word << 1) & 0xFFFFFFFF;
        }

        for ($i = 0; $i < 32; $i++) {
            if (($lo & 0x80000000) !== 0) {
                return $rank;
            }

            $rank++;
            $lo = ($lo << 1) & 0xFFFFFFFF;
        }

        // All zeros: the maximum rank this precision can produce.
        return $rank;
    }

    private static function alpha(int $m): float
    {
        return match ($m) {
            16 => 0.673,
            32 => 0.697,
            64 => 0.709,
            default => 0.7213 / (1 + 1.079 / $m),
        };
    }
}
