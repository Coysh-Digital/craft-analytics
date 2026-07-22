<?php

namespace coyshdigital\craftanalytics\uniques;

use coyshdigital\craftanalytics\models\Settings;
use coyshdigital\craftanalytics\Plugin;
use Craft;
use yii\base\Component;

/**
 * Portable driver: the sketch lives in a blob on the rollup row.
 *
 * Works on any MySQL/Postgres install with no extra infrastructure, which is
 * why it is the fallback when Redis isn't there. Costs a read-modify-write of
 * the blob per bucket per drain — cheap, because there are few buckets.
 */
class HllUniqueCounter extends Component implements UniqueCounterInterface
{
    public ?Settings $settings = null;

    public function name(): string
    {
        return Settings::UNIQUES_DRIVER_HLL;
    }

    public function accuracy(): string
    {
        $error = 1.04 / sqrt(1 << $this->precision()) * 100;

        return sprintf('±%.1f%%', $error);
    }

    public function storesOnRow(): bool
    {
        return true;
    }

    public function record(UniqueScope $scope, array $hashes, ?string $currentSketch): ?string
    {
        $sketch = $currentSketch !== null && $currentSketch !== ''
            ? $this->readOrRestart($currentSketch)
            : new Hll($this->precision());

        foreach ($hashes as $hash) {
            $sketch->add($hash);
        }

        return $sketch->serialize();
    }

    public function estimate(array $scopes, iterable $sketches = []): int
    {
        return Hll::mergeAll($sketches, $this->precision(), self::warn(...))->count();
    }

    /**
     * Reads the sketch already on the row, or starts a new one if it cannot be
     * read.
     *
     * A blob damaged by a truncated write or an interrupted restore is stored,
     * so it is re-read on every write to that row — throwing here would stop
     * the drain for good, and clearing the cache would not fix it because the
     * fault is in the database. A blob at a stale precision is the same shape
     * of problem and far commoner: `hllPrecision` is configurable, and a row
     * written before it changed can neither be merged nor read.
     *
     * Restarting costs that one row's history and makes it usable again on the
     * next write. The alternative is a row that is unreadable forever.
     */
    private function readOrRestart(string $blob): Hll
    {
        try {
            $sketch = Hll::deserialize($blob);
        } catch (\InvalidArgumentException $e) {
            self::warn($e);

            return new Hll($this->precision());
        }

        if ($sketch->precision() !== $this->precision()) {
            self::warn(new \InvalidArgumentException(sprintf(
                'Sketch is at precision %d but hllPrecision is now %d.',
                $sketch->precision(),
                $this->precision(),
            )));

            return new Hll($this->precision());
        }

        return $sketch;
    }

    private static function warn(\InvalidArgumentException $e): void
    {
        Craft::warning(
            'Unreadable unique-visitor sketch, starting a fresh one for this bucket: ' . $e->getMessage()
            . ' Its unique count is lost; every other figure is unaffected.',
            __METHOD__,
        );
    }

    private function precision(): int
    {
        return ($this->settings ??= Plugin::getInstance()->getSettings())->hllPrecision;
    }
}
