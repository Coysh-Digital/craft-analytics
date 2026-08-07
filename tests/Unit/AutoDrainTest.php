<?php

use coyshdigital\craftanalytics\ingest\Hit;
use coyshdigital\craftanalytics\models\Settings;
use coyshdigital\craftanalytics\write\AutoDrain;
use coyshdigital\craftanalytics\write\Drainer;
use coyshdigital\craftanalytics\write\DrainResult;
use coyshdigital\craftanalytics\write\SpoolStatus;
use coyshdigital\craftanalytics\write\SpoolWriter;
use yii\caching\ArrayCache;

beforeEach(function() {
    $this->spoolDir = sys_get_temp_dir() . '/ca-auto-drain-' . bin2hex(random_bytes(6));
    mkdir($this->spoolDir, 0775, true);
    $this->spool = new SpoolWriter(['spoolDir' => $this->spoolDir, 'settings' => new Settings()]);
});

afterEach(function() {
    foreach (glob($this->spoolDir . '/*') ?: [] as $file) {
        @unlink($file);
    }
    @rmdir($this->spoolDir);
});

/** A Drainer that never touches a database - it just remembers it was asked to run. */
function spiedDrainer(): Drainer
{
    return new class() extends Drainer {
        public bool $called = false;

        public function run(?int $now = null): DrainResult
        {
            $this->called = true;

            return new DrainResult();
        }
    };
}

function makeAutoDrain(string $spoolDir, Drainer $drainer, ?ArrayCache $cache = null): AutoDrain
{
    $status = new SpoolStatus();
    $status->spool = new SpoolWriter(['spoolDir' => $spoolDir, 'settings' => new Settings()]);

    $autoDrain = new AutoDrain();
    $autoDrain->drainer = $drainer;
    $autoDrain->status = $status;
    $autoDrain->cache = $cache ?? new ArrayCache();

    return $autoDrain;
}

test('does nothing when auto-drain is switched off', function() {
    $this->spool->write(new Hit(1, '/x', 'aaaaaaaaaaaaaaaa', 's1', time()));
    $drainer = spiedDrainer();

    makeAutoDrain($this->spoolDir, $drainer)->run(new Settings(['autoDrain' => false]));

    expect($drainer->called)->toBeFalse();
});

test('does nothing for a non-spool write driver', function() {
    $this->spool->write(new Hit(1, '/x', 'aaaaaaaaaaaaaaaa', 's1', time()));
    $drainer = spiedDrainer();

    makeAutoDrain($this->spoolDir, $drainer)->run(new Settings(['writeDriver' => Settings::WRITE_DRIVER_QUEUE]));

    expect($drainer->called)->toBeFalse();
});

test('does nothing when the spool is empty', function() {
    $drainer = spiedDrainer();

    makeAutoDrain($this->spoolDir, $drainer)->run(new Settings());

    expect($drainer->called)->toBeFalse();
});

test('drains when enabled, spooled, and there is a backlog', function() {
    $this->spool->write(new Hit(1, '/x', 'aaaaaaaaaaaaaaaa', 's1', time()));
    $drainer = spiedDrainer();

    makeAutoDrain($this->spoolDir, $drainer)->run(new Settings());

    expect($drainer->called)->toBeTrue();
});

test('a second request inside the throttle window is skipped', function() {
    $this->spool->write(new Hit(1, '/x', 'aaaaaaaaaaaaaaaa', 's1', time()));
    $cache = new ArrayCache();
    $first = spiedDrainer();
    $second = spiedDrainer();

    makeAutoDrain($this->spoolDir, $first, $cache)->run(new Settings());
    makeAutoDrain($this->spoolDir, $second, $cache)->run(new Settings());

    expect($first->called)->toBeTrue()
        ->and($second->called)->toBeFalse();
});

test('a backlog over the safety cap is left for cron rather than drained inline', function() {
    // The content doesn't need to be real hits - AutoDrain only ever looks at
    // the byte count before deciding whether to hand this request the drain.
    file_put_contents($this->spool->spoolPath(), str_repeat('x', 3 * 1024 * 1024));
    $drainer = spiedDrainer();

    makeAutoDrain($this->spoolDir, $drainer)->run(new Settings());

    expect($drainer->called)->toBeFalse();
});
