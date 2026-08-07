<?php

use coyshdigital\craftanalytics\ingest\Hit;
use coyshdigital\craftanalytics\models\Settings;
use coyshdigital\craftanalytics\write\SpoolStatus;
use coyshdigital\craftanalytics\write\SpoolWriter;

beforeEach(function() {
    $this->spoolDir = sys_get_temp_dir() . '/ca-spool-status-' . bin2hex(random_bytes(6));
    mkdir($this->spoolDir, 0775, true);
    $this->spool = new SpoolWriter(['spoolDir' => $this->spoolDir, 'settings' => new Settings()]);
    $this->status = new SpoolStatus();
    $this->status->spool = $this->spool;
});

afterEach(function() {
    foreach (glob($this->spoolDir . '/*') ?: [] as $file) {
        @unlink($file);
    }
    @rmdir($this->spoolDir);
});

test('a missing spool file has no backlog', function() {
    expect($this->status->hasBacklog())->toBeFalse()
        ->and($this->status->backlogBytes())->toBe(0);
});

test('a written hit shows up as backlog', function() {
    $this->spool->write(new Hit(
        siteId: 1,
        path: '/pricing',
        visitorHash: 'aaaaaaaaaaaaaaaa',
        sessionKey: 'session-1',
        timestamp: time(),
    ));

    expect($this->status->hasBacklog())->toBeTrue()
        ->and($this->status->backlogBytes())->toBeGreaterThan(0);
});
