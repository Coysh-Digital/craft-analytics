<?php

namespace coyshdigital\craftanalytics\enums;

/**
 * How credit for a session is divided between the campaigns that touched it.
 *
 * Only matters when a session has more than one campaign touch, which is
 * uncommon — most sessions arrive once and stay. When there is exactly one
 * touch, every model agrees, which is worth knowing before anyone spends an
 * afternoon choosing between them.
 */
enum AttributionModel: string
{
    /** The most recent touch takes all the credit. The industry default. */
    case LastClick = 'last-click';

    /** The touch that first brought them takes all the credit. */
    case FirstClick = 'first-click';

    /** Credit split evenly across every touch. */
    case Linear = 'linear';

    public function label(): string
    {
        return match ($this) {
            self::LastClick => 'Last click',
            self::FirstClick => 'First click',
            self::Linear => 'Linear',
        };
    }

    /**
     * Divides one session's credit between its touches, in order.
     *
     * @param int $touches how many campaigns touched the session
     * @return float[] the share each touch receives; sums to 1.0
     */
    public function weights(int $touches): array
    {
        if ($touches < 1) {
            return [];
        }

        return match ($this) {
            self::FirstClick => array_merge([1.0], array_fill(0, $touches - 1, 0.0)),
            // Cast: PHP's division returns an int when it divides evenly, and
            // a weight that is sometimes int and sometimes float is a trap
            // for anything downstream that type-checks it.
            self::Linear => array_fill(0, $touches, (float)(1 / $touches)),
            self::LastClick => array_merge(array_fill(0, $touches - 1, 0.0), [1.0]),
        };
    }
}
