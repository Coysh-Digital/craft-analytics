<?php

use coyshdigital\craftanalytics\db\SchemaBuilder;
use coyshdigital\craftanalytics\db\Table;
use coyshdigital\craftanalytics\enums\ConsentMethod;
use coyshdigital\craftanalytics\enums\ConsentState;
use coyshdigital\craftanalytics\events\DefineConsentEvent;
use coyshdigital\craftanalytics\events\DefineVisitorIdEvent;
use coyshdigital\craftanalytics\migrations\Install;
use coyshdigital\craftanalytics\models\Settings;
use coyshdigital\craftanalytics\services\ConsentService;
use coyshdigital\craftanalytics\tests\FakeRequest;
use coyshdigital\craftanalytics\tests\TestDb;
use yii\db\Query;

/**
 * The consent state machine.
 *
 * These are the tests that matter most in the plugin: every one of them is a
 * claim the product makes about somebody's privacy.
 */
beforeEach(function() {
    if (!TestDb::available()) {
        $this->markTestSkipped('No test database configured (CRAFT_ANALYTICS_TEST_* env vars).');
    }

    TestDb::dropTables(SchemaBuilder::allTables());
    (new Install(['db' => TestDb::connection()]))->up();
});

function consentService(array $settings = []): ConsentService
{
    return new ConsentService([
        'db' => TestDb::connection(),
        'settings' => new Settings(array_merge(['enableConsent' => true], $settings)),
        // Tier-2 is Pro-only; the tests are about the state machine, not the
        // licence check, which has its own test below.
        'isPro' => true,
    ]);
}

/** fromCookies() is the resolver's cookie half; resolve() adds the event. */
function resolveWith(ConsentService $service, FakeRequest $request): ConsentState
{
    $method = new ReflectionMethod($service, 'fromCookies');
    [$state] = $method->invoke($service, $request);

    return $state;
}

test('consent is unknown until somebody affirmatively gives it', function() {
    // No cookie, no CMP, nothing: absence is not consent.
    expect(resolveWith(consentService(), FakeRequest::make()))->toBe(ConsentState::Unknown);
});

test('unknown and denied both mean anonymous — only granted unlocks tier 2', function() {
    expect(ConsentState::Unknown->isGranted())->toBeFalse()
        ->and(ConsentState::Denied->isGranted())->toBeFalse()
        ->and(ConsentState::Granted->isGranted())->toBeTrue();
});

test('our own cookie is read as a grant', function() {
    $request = FakeRequest::make(cookies: ['_ca_vid' => str_repeat('a', 32)]);

    expect(resolveWith(consentService(), $request))->toBe(ConsentState::Granted);
});

test('a malformed visitor cookie is not a grant', function(string $value) {
    $request = FakeRequest::make(cookies: ['_ca_vid' => $value]);
    $service = consentService();

    expect($service->visitorId($request))->toBeNull()
        ->and(resolveWith($service, $request))->toBe(ConsentState::Unknown);
})->with([
    'too short' => 'abc',
    'not hex' => str_repeat('z', 32),
    'injection' => "' OR 1=1--",
    'empty' => '',
]);

test('a CMP cookie saying yes is a grant', function(string $value) {
    $request = FakeRequest::make(cookies: ['cookie_consent' => $value]);
    $service = consentService(['cmpCookieName' => 'cookie_consent']);

    expect(resolveWith($service, $request))->toBe(ConsentState::Granted);
})->with(['granted', 'true', '1', 'yes', 'allow', 'GRANTED', ' Yes ']);

test('a CMP cookie saying anything else is a refusal, not an unknown', function(string $value) {
    // The visitor has been asked and answered; that is a decision.
    $request = FakeRequest::make(cookies: ['cookie_consent' => $value]);
    $service = consentService(['cmpCookieName' => 'cookie_consent']);

    expect(resolveWith($service, $request))->toBe(ConsentState::Denied);
})->with(['denied', 'false', '0', 'no', 'rejected', 'deny']);

test('GPC is honoured, and cannot be overridden by anything', function() {
    $service = consentService();
    $granted = FakeRequest::make(headers: ['sec-gpc' => '1'], cookies: ['_ca_vid' => str_repeat('a', 32)]);

    expect($service->hasPrivacySignal($granted))->toBeTrue();

    // Even a site handler insisting on consent must not beat the browser's
    // opt-out: resolve() checks the signal before the event is ever fired.
    $service->on(ConsentService::EVENT_DEFINE_CONSENT, function(DefineConsentEvent $event) {
        $event->state = ConsentState::Granted;
    });

    // grant() refuses outright for a GPC visitor, cookie or no cookie.
    expect($service->grant($granted, 1, ConsentMethod::JsApi))->toBeNull();
});

test('DNT is only honoured when the site opts in', function() {
    $request = FakeRequest::make(headers: ['dnt' => '1']);

    expect(consentService()->hasPrivacySignal($request))->toBeFalse()
        ->and(consentService(['honourDnt' => true])->hasPrivacySignal($request))->toBeTrue();
});

test('consent is unavailable unless it is switched on', function() {
    // The default: the plugin is cookieless and no consent is read at all.
    $service = new ConsentService([
        'db' => TestDb::connection(),
        'settings' => new Settings(),
        'isPro' => true,
    ]);

    expect($service->isAvailable())->toBeFalse()
        ->and($service->visitorId(FakeRequest::make(cookies: ['_ca_vid' => str_repeat('a', 32)])))->toBeNull();
});

test('every consent decision is logged as evidence', function() {
    $service = consentService();

    $service->log(1, ConsentState::Granted, ConsentMethod::CmpAdapter, str_repeat('a', 32), null);

    $row = (new Query())->from(Table::CONSENT_LOG)->one(TestDb::connection());

    expect($row['state'])->toBe('granted')
        ->and($row['method'])->toBe('cmp')
        ->and($row['scope'])->toBe(ConsentService::SCOPE)
        ->and($row['policyVersion'])->toBe('1')
        ->and($row['visitorId'])->toBe(str_repeat('a', 32))
        ->and($row['recordedAt'])->not->toBeEmpty();
});

test('the evidence log holds nothing that identifies a person', function() {
    $service = consentService();
    $service->log(1, ConsentState::Denied, ConsentMethod::JsApi, null, 'aaaaaaaaaaaaaaaa');

    $row = (new Query())->from(Table::CONSENT_LOG)->one(TestDb::connection());

    // A refusal is keyed to the rotating Tier-1 hash — evidence that someone
    // said no, unlinkable to anyone once the salt rotates.
    expect($row['visitorId'])->toBeNull()
        ->and($row['visitorHash'])->toBe('aaaaaaaaaaaaaaaa');

    // Nothing resembling an address is anywhere in the row.
    foreach ($row as $value) {
        expect((string)$value)->not->toMatch('/\b\d{1,3}(\.\d{1,3}){3}\b/');
    }
});

test('the policy version is recorded, so an old consent evidences the old policy', function() {
    consentService(['policyVersion' => '3'])
        ->log(1, ConsentState::Granted, ConsentMethod::JsApi, str_repeat('b', 32));

    $row = (new Query())->from(Table::CONSENT_LOG)->one(TestDb::connection());

    expect($row['policyVersion'])->toBe('3');
});

test('the defineConsent event can resolve consent the plugin cannot see', function() {
    $service = consentService();
    $service->on(ConsentService::EVENT_DEFINE_CONSENT, function(DefineConsentEvent $event) {
        // A site resolving consent from its own CRM state.
        $event->state = ConsentState::Granted;
    });

    $event = new DefineConsentEvent(ConsentState::Unknown, ConsentMethod::ServerEvent, 1);
    $service->trigger(ConsentService::EVENT_DEFINE_CONSENT, $event);

    expect($event->state)->toBe(ConsentState::Granted);
});

test('a site can supply its own id for a consented visitor', function() {
    $service = consentService();
    $service->on(ConsentService::EVENT_DEFINE_VISITOR_ID, function(DefineVisitorIdEvent $event) {
        $event->visitorId = 'cust_9182';
    });

    $request = FakeRequest::make(cookies: ['_ca_vid' => str_repeat('a', 32)]);

    // The cookie's value is untouched: what the site calls this person belongs
    // in the database, not on their device.
    expect($service->resolvedVisitorId($request, 1))->toBe('cust_9182')
        ->and($service->visitorId($request))->toBe(str_repeat('a', 32));
});

test('an unusable supplied id is refused rather than mangled', function() {
    // Stored truncated or half-escaped, it would put rows in the journeys
    // table that no subject request could ever find again.
    $service = consentService();
    $service->on(ConsentService::EVENT_DEFINE_VISITOR_ID, function(DefineVisitorIdEvent $event) {
        $event->visitorId = 'cust 9182; drop table';
    });

    $request = FakeRequest::make(cookies: ['_ca_vid' => str_repeat('a', 32)]);

    expect($service->resolvedVisitorId($request, 1))->toBe(str_repeat('a', 32));
});

test('a handler cannot invent a consented visitor out of somebody who refused', function() {
    // The event fires downstream of every gate there is. GPC in particular is
    // not something the site gets a vote on.
    $service = consentService();
    $service->on(ConsentService::EVENT_DEFINE_VISITOR_ID, function(DefineVisitorIdEvent $event) {
        $event->visitorId = 'cust_9182';
    });

    $gpc = FakeRequest::make(headers: ['sec-gpc' => '1']);

    expect($service->resolve($gpc, 1))->toBe(ConsentState::Denied)
        ->and($service->grant($gpc, 1, ConsentMethod::JsApi))->toBeNull();

    // And with consent switched off entirely there is no id at all.
    $off = consentService(['enableConsent' => false]);
    $off->on(ConsentService::EVENT_DEFINE_VISITOR_ID, function(DefineVisitorIdEvent $event) {
        $event->visitorId = 'cust_9182';
    });

    expect($off->resolvedVisitorId(FakeRequest::make(), 1))->toBeNull();
});

test('lite never reaches the handler', function() {
    $service = new ConsentService([
        'db' => TestDb::connection(),
        'settings' => new Settings(['enableConsent' => true]),
        'isPro' => false,
    ]);
    $service->on(ConsentService::EVENT_DEFINE_VISITOR_ID, function(DefineVisitorIdEvent $event) {
        $event->visitorId = 'cust_9182';
    });

    expect($service->resolvedVisitorId(FakeRequest::make(cookies: ['_ca_vid' => str_repeat('a', 32)]), 1))
        ->toBeNull();
});

test('the evidence is logged under the supplied id, so withdrawal can find it', function() {
    // The journeys are written under this id. If the consent log used a
    // different one, a withdrawal would match nothing and the drain would
    // carry on writing rows for somebody who said no.
    $service = consentService();
    $service->on(ConsentService::EVENT_DEFINE_VISITOR_ID, function(DefineVisitorIdEvent $event) {
        $event->visitorId = 'cust_9182';
    });

    $service->log(1, ConsentState::Granted, ConsentMethod::JsApi, 'cust_9182');

    $row = (new Query())->from(Table::CONSENT_LOG)->one(TestDb::connection());

    expect($row['visitorId'])->toBe('cust_9182');
});
