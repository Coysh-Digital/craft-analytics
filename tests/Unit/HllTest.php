<?php

use coyshdigital\craftanalytics\uniques\Hll;

/**
 * Deterministic stand-in for real visitor hashes: 8 uniformly distributed
 * bytes, exactly what IdentityService produces.
 */
function visitorHashes(int $count, int $seed = 0): Generator
{
    for ($i = 0; $i < $count; $i++) {
        yield substr(hash('sha256', "visitor:$seed:$i"), 0, 16);
    }
}

function sketchOf(int $count, int $seed = 0, int $precision = Hll::DEFAULT_PRECISION): Hll
{
    $hll = new Hll($precision);

    foreach (visitorHashes($count, $seed) as $hash) {
        $hll->add($hash);
    }

    return $hll;
}

/** Standard error of HLL is 1.04/sqrt(m); allow 3 sigma before failing. */
function errorBound(int $precision): float
{
    return 3 * 1.04 / sqrt(1 << $precision);
}

test('an empty sketch counts zero', function() {
    expect((new Hll())->count())->toBe(0);
});

test('tiny cardinalities are counted exactly', function(int $n) {
    // Linear counting is exact until registers start colliding, which is
    // what most page-hours look like: a handful of visitors, counted right.
    expect(sketchOf($n)->count())->toBe($n);
})->with([1, 2, 5, 10, 50]);

test('estimates stay within the error bound at known cardinalities', function(int $n) {
    // From ~100 up, register collisions make this an estimate rather than a
    // count — e.g. 100 distinct visitors is reported as 101. That is the
    // trade the sketch exists to make, and the CP says so (±1.6% at p=12).
    $estimate = sketchOf($n)->count();
    $error = abs($estimate - $n) / $n;

    expect($error)->toBeLessThan(errorBound(Hll::DEFAULT_PRECISION));
})->with([100, 1000, 10000, 100000]);

test('a million distinct visitors estimate within the error bound', function() {
    $n = 1000000;
    $estimate = sketchOf($n)->count();
    $error = abs($estimate - $n) / $n;

    expect($error)->toBeLessThan(errorBound(Hll::DEFAULT_PRECISION));
})->group('slow');

test('higher precision is more accurate', function() {
    $n = 100000;
    $coarse = abs(sketchOf($n, 0, 11)->count() - $n) / $n;
    $fine = abs(sketchOf($n, 0, 14)->count() - $n) / $n;

    expect($fine)->toBeLessThan($coarse)
        ->and($fine)->toBeLessThan(errorBound(14));
});

test('adding the same visitor repeatedly counts once', function() {
    $hll = new Hll();
    $hash = substr(hash('sha256', 'the same person'), 0, 16);

    for ($i = 0; $i < 1000; $i++) {
        $hll->add($hash);
    }

    expect($hll->count())->toBe(1);
});

test('merge equals the sketch of the union — never the sum', function() {
    // Two overlapping days: 6,000 distinct people, 2,000 of whom came back.
    $a = new Hll();
    $b = new Hll();

    foreach (visitorHashes(4000, 1) as $hash) {
        $a->add($hash);
    }

    $shared = iterator_to_array(visitorHashes(2000, 2));
    foreach ($shared as $hash) {
        $a->add($hash);
        $b->add($hash);
    }

    foreach (visitorHashes(2000, 3) as $hash) {
        $b->add($hash);
    }

    $union = sketchOf(0);
    $union->merge($a);
    $union->merge($b);

    $trueUnion = 8000;
    $error = abs($union->count() - $trueUnion) / $trueUnion;

    expect($error)->toBeLessThan(errorBound(Hll::DEFAULT_PRECISION));

    // Summing the two would give 10,000 — the 2,000 returning visitors
    // double-counted. This is the bug the whole sketch exists to avoid.
    expect($a->count() + $b->count())->toBeGreaterThan($union->count() + 1000);
});

test('merging is commutative and idempotent', function() {
    $a = sketchOf(5000, 1);
    $b = sketchOf(5000, 2);

    $ab = sketchOf(0);
    $ab->merge($a);
    $ab->merge($b);

    $ba = sketchOf(0);
    $ba->merge($b);
    $ba->merge($a);

    expect($ab->count())->toBe($ba->count());

    // Merging the same sketch again adds nothing — compaction can be retried
    // safely.
    $ab->merge($a);
    expect($ab->count())->toBe($ba->count());
});

test('mergeAll answers a range from its parts', function() {
    // Seven days of 1,000 distinct visitors each, no overlap.
    $blobs = [];
    for ($day = 0; $day < 7; $day++) {
        $blobs[] = sketchOf(1000, $day + 10)->serialize();
    }

    $merged = Hll::mergeAll($blobs);
    $error = abs($merged->count() - 7000) / 7000;

    expect($error)->toBeLessThan(errorBound(Hll::DEFAULT_PRECISION));
});

test('mergeAll tolerates empty and null blobs', function() {
    $merged = Hll::mergeAll([null, '', sketchOf(50)->serialize()]);

    expect($merged->count())->toBe(50);
});

test('mergeAll skips what it cannot read rather than failing the range', function() {
    $skipped = [];
    $merged = Hll::mergeAll(
        [
            sketchOf(50)->serialize(),
            'not a sketch at all',
            // The likelier fault by far: a row written before hllPrecision
            // was changed. Unmergeable, but no reason to lose the range.
            sketchOf(50, 99, 14)->serialize(),
            sketchOf(50, 500)->serialize(),
        ],
        Hll::DEFAULT_PRECISION,
        static function(InvalidArgumentException $e) use (&$skipped) {
            $skipped[] = $e->getMessage();
        },
    );

    // The two readable sketches still answer, and the caller was told what
    // was dropped rather than it happening silently.
    expect($skipped)->toHaveCount(2)
        ->and(abs($merged->count() - 100) / 100)->toBeLessThan(errorBound(Hll::DEFAULT_PRECISION));
});

test('a sketch survives serialisation unchanged', function(int $n) {
    $original = sketchOf($n);
    $restored = Hll::deserialize($original->serialize());

    expect($restored->count())->toBe($original->count())
        ->and($restored->precision())->toBe($original->precision())
        ->and($restored->isSparse())->toBe($original->isSparse());
})->with([0, 1, 100, 5000, 100000]);

test('sparse and dense representations agree', function() {
    $sparse = sketchOf(100);
    expect($sparse->isSparse())->toBeTrue();

    // Force the same visitors into a dense sketch by merging into one that
    // has already been promoted.
    $dense = sketchOf(50000);
    expect($dense->isSparse())->toBeFalse();

    $mergedIntoDense = sketchOf(50000);
    $mergedIntoDense->merge($sparse);

    $mergedIntoSparse = sketchOf(100);
    $mergedIntoSparse->merge($dense);

    // Same union either way round, regardless of which side was promoted.
    expect($mergedIntoDense->count())->toBe($mergedIntoSparse->count());
});

test('sparse sketches are small and dense ones are bounded', function() {
    // The claim that sketch storage does not track traffic rests on this:
    // a typical page-hour is a few hundred bytes, not 4 KB.
    expect(sketchOf(40)->byteSize())->toBeLessThan(200)
        ->and(sketchOf(100)->byteSize())->toBeLessThan(400);

    $dense = sketchOf(50000);
    expect($dense->isSparse())->toBeFalse()
        ->and($dense->byteSize())->toBe((1 << Hll::DEFAULT_PRECISION) + 3);
});

test('promotion to dense happens once the sparse map stops being cheaper', function() {
    $hll = new Hll();
    $promoted = false;

    foreach (visitorHashes(3000) as $hash) {
        $hll->add($hash);

        if (!$hll->isSparse()) {
            $promoted = true;
            break;
        }
    }

    expect($promoted)->toBeTrue()
        ->and($hll->byteSize())->toBeLessThanOrEqual((1 << Hll::DEFAULT_PRECISION) + 3);
});

test('sketches of different precisions refuse to merge', function() {
    $a = new Hll(12);
    $b = new Hll(14);

    expect(fn() => $a->merge($b))->toThrow(InvalidArgumentException::class);
});

test('an out-of-range precision is rejected', function(int $p) {
    expect(fn() => new Hll($p))->toThrow(InvalidArgumentException::class);
})->with([0, 10, 15, 32]);

test('a malformed visitor hash is rejected', function(string $hash) {
    expect(fn() => (new Hll())->add($hash))->toThrow(InvalidArgumentException::class);
})->with(['', 'nothex!!nothex!!', 'abcd', str_repeat('a', 32)]);

test('a corrupt blob is rejected rather than silently miscounted', function() {
    expect(fn() => Hll::deserialize(''))->toThrow(InvalidArgumentException::class)
        ->and(fn() => Hll::deserialize(pack('CCC', 99, 12, 0)))->toThrow(InvalidArgumentException::class)
        ->and(fn() => Hll::deserialize(pack('CCC', 1, 12, 1) . 'short'))->toThrow(InvalidArgumentException::class)
        ->and(fn() => Hll::deserialize(pack('CCC', 1, 12, 0) . 'xx'))->toThrow(InvalidArgumentException::class);
});
