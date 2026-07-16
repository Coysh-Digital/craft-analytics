<?php

use coyshdigital\craftanalytics\db\Table;
use coyshdigital\craftanalytics\migrations\Install;
use coyshdigital\craftanalytics\models\Settings;
use coyshdigital\craftanalytics\services\IdentityService;
use coyshdigital\craftanalytics\services\SaltService;
use coyshdigital\craftanalytics\tests\TestDb;
use yii\db\Query;

beforeEach(function() {
    if (!TestDb::available()) {
        $this->markTestSkipped('No test database configured (CRAFT_ANALYTICS_TEST_* env vars).');
    }

    $db = TestDb::connection();
    TestDb::dropTables([Table::DRAIN_LOG, Table::SALTS, Table::DIMENSIONS]);
    (new Install(['db' => $db]))->up();
});

function saltService(array $config = []): SaltService
{
    return new SaltService(array_merge([
        'db' => TestDb::connection(),
        'settings' => new Settings(),
        'cache' => false,
    ], $config));
}

test('a salt is generated on first use', function() {
    $salt = saltService()->getCurrentSalt();

    expect($salt)->toMatch('/^[0-9a-f]{64}$/');
});

test('the salt is stable within a rotation window', function() {
    $service = saltService();

    expect($service->getCurrentSalt())->toBe($service->getCurrentSalt());

    // A fresh instance reads the same stored salt, so hashes stay comparable
    // across requests and processes.
    expect(saltService()->getCurrentSalt())->toBe($service->getCurrentSalt());
});

test('rotation destroys the previous salt rather than archiving it', function() {
    $service = saltService();
    $before = $service->getCurrentSalt();

    $service->rotate();

    $rows = (new Query())->from(Table::SALTS)->all(TestDb::connection());

    expect($rows)->toHaveCount(1)
        ->and($rows[0]['salt'])->not->toBe($before);

    // The old salt exists nowhere in the table — it cannot be recovered to
    // re-link yesterday's hashes.
    $found = (new Query())->from(Table::SALTS)->where(['salt' => $before])->exists(TestDb::connection());
    expect($found)->toBeFalse();
});

test('rotation makes the same visitor unrecognisable — the banner-free claim', function() {
    $salts = saltService();
    $identity = new IdentityService(['salts' => $salts]);

    $ip = '203.0.113.7';
    $ua = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) Chrome/126.0.0.0';

    $before = $identity->visitorHash($ip, $ua, 1);
    // Same inputs, same window: the visitor is recognisable, which is what
    // makes same-day uniques work at all.
    expect($identity->visitorHash($ip, $ua, 1))->toBe($before);

    $salts->rotate();
    $after = $identity->visitorHash($ip, $ua, 1);

    // Same person, same device, same address — and no way to connect the two
    // hashes, because the salt that produced the first no longer exists.
    expect($after)->not->toBe($before);
});

test('visitor hashes are scoped per site', function() {
    $identity = new IdentityService(['salts' => saltService()]);
    $ip = '203.0.113.7';
    $ua = 'Chrome/126';

    expect($identity->visitorHash($ip, $ua, 1))->not->toBe($identity->visitorHash($ip, $ua, 2));
});

test('a visitor hash is 8 bytes and reveals nothing about the address', function() {
    $identity = new IdentityService(['salts' => saltService()]);
    $hash = $identity->visitorHash('203.0.113.7', 'Chrome/126', 1);

    expect($hash)->toMatch('/^[0-9a-f]{16}$/')
        ->and($hash)->not->toContain('203')
        ->and(strlen(hex2bin($hash) ?: ''))->toBe(IdentityService::HASH_BYTES);
});

test('an elapsed window rotates automatically on next use', function() {
    $service = saltService(['settings' => new Settings(['saltRotationInterval' => 3600])]);
    $before = $service->getCurrentSalt();

    // Backdate the stored rotation deadline: the next read is due a rotation.
    TestDb::connection()->createCommand()->update(Table::SALTS, [
        'nextRotation' => gmdate('Y-m-d H:i:s', time() - 60),
    ], ['id' => 1])->execute();

    expect(saltService(['settings' => new Settings(['saltRotationInterval' => 3600])])->getCurrentSalt())
        ->not->toBe($before);
});

test('daily rotation is aligned to the configured quiet hour', function() {
    $service = saltService(['settings' => new Settings(['saltRotationHour' => 4])]);

    $next = $service->nextRotationAfter(mktime(12, 0, 0, 7, 16, 2026) ?: time());

    expect((int)gmdate('G', $next))->toBe(4);
});
