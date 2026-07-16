<?php

namespace coyshdigital\craftanalytics\uniques;

use coyshdigital\craftanalytics\models\Settings;
use coyshdigital\craftanalytics\Plugin;
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
            ? Hll::deserialize($currentSketch)
            : new Hll($this->precision());

        foreach ($hashes as $hash) {
            $sketch->add($hash);
        }

        return $sketch->serialize();
    }

    public function estimate(array $scopes, iterable $sketches = []): int
    {
        return Hll::mergeAll($sketches, $this->precision())->count();
    }

    private function precision(): int
    {
        return ($this->settings ??= Plugin::getInstance()->getSettings())->hllPrecision;
    }
}
