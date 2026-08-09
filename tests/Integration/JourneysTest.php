<?php

use coyshdigital\craftanalytics\db\SchemaBuilder;
use coyshdigital\craftanalytics\db\Table;
use coyshdigital\craftanalytics\ingest\Hit;
use coyshdigital\craftanalytics\migrations\Install;
use coyshdigital\craftanalytics\models\Settings;
use coyshdigital\craftanalytics\rollup\JourneyRecorder;
use coyshdigital\craftanalytics\services\DimensionsService;
use coyshdigital\craftanalytics\services\PrivacyService;
use coyshdigital\craftanalytics\tests\TestDb;
use yii\db\Query;

/**
 * The consented raw layer — the one place a row means "this person did this",
 * and the only thing a subject request can actually touch.
 */
beforeEach(function() {
    if (!TestDb::available()) {
        $this->markTestSkipped('No test database configured (CRAFT_ANALYTICS_TEST_* env vars).');
    }

    $db = TestDb::connection();
    TestDb::dropTables(SchemaBuilder::allTables());
    (new Install(['db' => $db]))->up();

    $this->settings = new Settings([
        'enableConsent' => true,
        'enableJourneys' => true,
    ]);
});

function recorder(object $ctx, array $overrides = []): JourneyRecorder
{
    return new JourneyRecorder(array_merge([
        'db' => TestDb::connection(),
        'settings' => $ctx->settings,
        'dimensions' => new DimensionsService(['db' => TestDb::connection()]),
        'isPro' => true,
    ], $overrides));
}

function consentedHit(?string $visitorId, string $path = '/pricing', ?int $userId = null, bool $countView = true): Hit
{
    return new Hit(
        siteId: 1,
        path: $path,
        visitorHash: 'aaaaaaaaaaaaaaaa',
        sessionKey: 'sess0000000000000000000000000001',
        timestamp: mktime(10, 0, 0, 7, 16, 2026),
        countView: $countView,
        visitorId: $visitorId,
        userId: $userId,
    );
}

function journeyRows(): array
{
    return (new Query())->from(Table::JOURNEYS)->orderBy('sequence')->all(TestDb::connection());
}

function logConsent(string $visitorId, string $state, string $recordedAt): void
{
    TestDb::connection()->createCommand()->insert(Table::CONSENT_LOG, [
        'siteId' => 1,
        'visitorId' => $visitorId,
        'state' => $state,
        'method' => 'js',
        'scope' => 'analytics',
        'policyVersion' => '1',
        'recordedAt' => $recordedAt,
    ])->execute();
}

test('a consented visitor’s pageviews are recorded in order', function() {
    $visitorId = str_repeat('a', 32);

    recorder($this)->record([
        consentedHit($visitorId, '/'),
        consentedHit($visitorId, '/pricing'),
        consentedHit($visitorId, '/contact'),
    ]);

    $rows = journeyRows();

    expect($rows)->toHaveCount(3)
        // Cast: MySQL hands integers back as strings, Postgres as ints.
        ->and(array_map('intval', array_column($rows, 'sequence')))->toBe([0, 1, 2])
        ->and(array_column($rows, 'visitorId'))->each->toBe($visitorId);
});

test('journeys record only what was consented to', function() {
    $visitorId = str_repeat('a', 32);

    recorder($this)->record([
        consentedHit($visitorId, '/'),
        // Not consented: no visitor ID, so no row — this is everybody else.
        consentedHit(null, '/anonymous-page'),
        // A dwell-only beacon is the same view being reported on, not a
        // second step in the journey.
        consentedHit($visitorId, '/', countView: false),
    ]);

    expect(journeyRows())->toHaveCount(1);
});

/**
 * The regression this file exists for.
 *
 * Hits are spooled when consent is live, but the drain runs later. If someone
 * withdraws in between, withdrawal erases their rows — and the drain would
 * then write the already-spooled hits straight back, quietly resurrecting the
 * data of somebody who said no. Consent has to be re-checked at write time.
 */
test('a visitor who withdrew before the drain ran is not resurrected by it', function() {
    $visitorId = str_repeat('a', 32);

    // They consented, browsed (hits go to the spool), then withdrew.
    logConsent($visitorId, 'granted', '2026-07-16 10:00:00');
    logConsent($visitorId, 'denied', '2026-07-16 10:05:00');

    // The drain catches up afterwards with hits captured while consent was live.
    $written = recorder($this)->record([
        consentedHit($visitorId, '/'),
        consentedHit($visitorId, '/pricing'),
    ]);

    expect($written)->toBe(0)
        ->and(journeyRows())->toBeEmpty();
});

test('withdrawing does not silence a different visitor who is still consented', function() {
    $withdrawn = str_repeat('a', 32);
    $stillConsented = str_repeat('b', 32);

    logConsent($withdrawn, 'granted', '2026-07-16 10:00:00');
    logConsent($withdrawn, 'denied', '2026-07-16 10:05:00');
    logConsent($stillConsented, 'granted', '2026-07-16 10:00:00');

    recorder($this)->record([
        consentedHit($withdrawn, '/'),
        consentedHit($stillConsented, '/pricing'),
    ]);

    $rows = journeyRows();

    expect($rows)->toHaveCount(1)
        ->and($rows[0]['visitorId'])->toBe($stillConsented);
});

test('someone who withdrew and then consented again is recorded again', function() {
    $visitorId = str_repeat('a', 32);

    // The latest decision is the one that counts, not the first.
    logConsent($visitorId, 'granted', '2026-07-16 10:00:00');
    logConsent($visitorId, 'denied', '2026-07-16 10:05:00');
    logConsent($visitorId, 'granted', '2026-07-16 10:09:00');

    expect(recorder($this)->record([consentedHit($visitorId, '/')]))->toBe(1);
});

test('nothing is recorded without consent switched on', function() {
    $this->settings->enableConsent = false;

    $written = recorder($this)->record([consentedHit(str_repeat('a', 32))]);

    expect($written)->toBe(0)
        ->and(journeyRows())->toBeEmpty();
});

test('nothing is recorded without the journeys layer switched on', function() {
    $this->settings->enableJourneys = false;

    expect(recorder($this)->record([consentedHit(str_repeat('a', 32))]))->toBe(0);
});

test('nothing is recorded without Pro', function() {
    // Gated at the service, not in the UI: no route or event can smuggle
    // past a licence check that lives here.
    expect(recorder($this, ['isPro' => false])->record([consentedHit(str_repeat('a', 32))]))->toBe(0)
        ->and(journeyRows())->toBeEmpty();
});

test('the user ID is only stored when separately enabled', function() {
    $visitorId = str_repeat('a', 32);

    // Consent alone is not consent to be named.
    recorder($this)->record([consentedHit($visitorId, '/', userId: 42)]);
    expect(journeyRows()[0]['userId'])->toBeNull();

    $this->settings->associateUserId = true;
    recorder($this)->record([consentedHit($visitorId, '/other', userId: 42)]);

    $rows = journeyRows();
    expect((int)$rows[1]['userId'])->toBe(42);
});

test('a session spanning two batches keeps counting', function() {
    $visitorId = str_repeat('a', 32);

    recorder($this)->record([consentedHit($visitorId, '/'), consentedHit($visitorId, '/two')]);
    // A later drain of the same session must not restart the sequence.
    recorder($this)->record([consentedHit($visitorId, '/three')]);

    $sequences = array_map('intval', array_column(journeyRows(), 'sequence'));

    expect($sequences)->toBe([0, 1, 2]);
});

test('a subject export returns their journeys and says what it omits', function() {
    $visitorId = str_repeat('a', 32);
    recorder($this)->record([consentedHit($visitorId, '/'), consentedHit($visitorId, '/pricing')]);

    $privacy = new PrivacyService([
        'db' => TestDb::connection(),
        'settings' => $this->settings,
        'isPro' => true,
    ]);
    $export = $privacy->export(visitorId: $visitorId);

    expect($export['journeys'])->toHaveCount(2)
        ->and($export['journeys'][0]['path'])->toBe('/')
        ->and($export['subject']['visitorId'])->toBe($visitorId);

    // The surprising-but-correct part, stated in the export itself.
    expect(implode(' ', $export['notes']))
        ->toContain('no personal data')
        ->toContain('No IP address is held');
});

test('erasing a subject removes their journeys but keeps the consent evidence', function() {
    $visitorId = str_repeat('a', 32);
    recorder($this)->record([consentedHit($visitorId, '/'), consentedHit($visitorId, '/pricing')]);

    TestDb::connection()->createCommand()->insert(Table::CONSENT_LOG, [
        'siteId' => 1, 'visitorId' => $visitorId, 'state' => 'granted', 'method' => 'js',
        'scope' => 'analytics', 'policyVersion' => '1', 'recordedAt' => gmdate('Y-m-d H:i:s'),
    ])->execute();

    $privacy = new PrivacyService(['db' => TestDb::connection(), 'settings' => $this->settings, 'isPro' => true]);
    $result = $privacy->erase(visitorId: $visitorId);

    expect($result['journeys'])->toBe(2)
        ->and(journeyRows())->toBeEmpty()
        // Kept: it is the evidence that the erased processing was lawful.
        ->and($result['consentLog'])->toBe(0)
        ->and((new Query())->from(Table::CONSENT_LOG)->count('*', TestDb::connection()))->toEqual(1);
});

test('the consent evidence can be erased too, when asked explicitly', function() {
    $visitorId = str_repeat('a', 32);

    TestDb::connection()->createCommand()->insert(Table::CONSENT_LOG, [
        'siteId' => 1, 'visitorId' => $visitorId, 'state' => 'granted', 'method' => 'js',
        'scope' => 'analytics', 'policyVersion' => '1', 'recordedAt' => gmdate('Y-m-d H:i:s'),
    ])->execute();

    $privacy = new PrivacyService(['db' => TestDb::connection(), 'settings' => $this->settings, 'isPro' => true]);
    $result = $privacy->erase(visitorId: $visitorId, includeConsentLog: true);

    expect($result['consentLog'])->toBe(1)
        ->and((new Query())->from(Table::CONSENT_LOG)->count('*', TestDb::connection()))->toEqual(0);
});

test('erasing one subject leaves everyone else alone', function() {
    recorder($this)->record([
        consentedHit(str_repeat('a', 32), '/'),
        consentedHit(str_repeat('b', 32), '/'),
    ]);

    $privacy = new PrivacyService(['db' => TestDb::connection(), 'settings' => $this->settings, 'isPro' => true]);
    $privacy->erase(visitorId: str_repeat('a', 32));

    $rows = journeyRows();
    expect($rows)->toHaveCount(1)
        ->and($rows[0]['visitorId'])->toBe(str_repeat('b', 32));
});

test('a subject request needs a subject', function() {
    $privacy = new PrivacyService(['db' => TestDb::connection(), 'settings' => $this->settings]);

    expect(fn() => $privacy->export())->toThrow(InvalidArgumentException::class)
        ->and(fn() => $privacy->erase())->toThrow(InvalidArgumentException::class);
});

/**
 * The Privacy screen's figures are per-site like every other figure.
 *
 * counts() ran COUNT(*) over the whole journeys and consent tables, and the
 * screen renders to anyone with the view permission - so a user restricted to
 * one site read every site's consented-visitor and journey totals. Aggregates
 * only, which is why this is a small hole rather than a large one, but it
 * contradicted the per-site model the rest of the plugin enforces.
 */
test('privacy counts can be scoped to the sites a viewer may see', function() {
    $db = TestDb::connection();
    $privacy = new coyshdigital\craftanalytics\services\PrivacyService(['db' => $db]);

    foreach ([[1, 'visitor-a'], [1, 'visitor-b'], [2, 'visitor-c']] as [$siteId, $visitorId]) {
        $db->createCommand()->insert(Table::JOURNEYS, [
            'siteId' => $siteId,
            'visitorId' => $visitorId,
            'sessionId' => 'sess-' . $visitorId,
            'sequence' => 1,
            'pathDimId' => 1,
            'occurredAt' => gmdate('Y-m-d H:i:s'),
        ])->execute();
    }

    $everySite = $privacy->counts();
    $siteOne = $privacy->counts([1]);

    expect($everySite['journeyRows'])->toBe(3)
        ->and($everySite['consentedVisitors'])->toBe(3)
        // Site two's visitor is not this viewer's to count.
        ->and($siteOne['journeyRows'])->toBe(2)
        ->and($siteOne['consentedVisitors'])->toBe(2);
});
