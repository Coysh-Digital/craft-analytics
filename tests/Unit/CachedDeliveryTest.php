<?php

use coyshdigital\craftanalytics\models\Settings;

/**
 * Behind a full-page cache, a pageview must be counted once.
 *
 * Blitz's default setup serves its cache from inside PHP: it builds a
 * response, calls send() and exits. That fires the same EVENT_AFTER_SEND a
 * real render does, so the server-side capture ran on cache hits and counted
 * them - while the beacon *also* counted them, because the nonce baked into
 * the cached HTML had been claimed long ago by whoever generated it. Three
 * visitors to a cached page produced five views.
 *
 * The discriminator is the nonce: it is issued while the page is rendered, so
 * if none was issued this request, PHP did not render this page and it came
 * out of a cache. These tests pin the decision that follows from that.
 *
 * Verified against a real Blitz install: three visitors on a cached page now
 * produce exactly three views.
 */
test('a cached page is left to the beacon in hybrid mode', function() {
    $settings = new Settings();
    $settings->trackingMode = Settings::TRACKING_MODE_HYBRID;
    $settings->injectScript = true;

    expect(cachedDeliveryApplies($settings))->toBeTrue();
});

test('server-only mode still counts a cached page itself', function() {
    // There is no beacon to hand the pageview to, so refusing to count it
    // would lose it outright. A cache in server-only mode under-counts either
    // way - the settings screen says so plainly rather than this pretending
    // otherwise.
    $settings = new Settings();
    $settings->trackingMode = Settings::TRACKING_MODE_SERVER;
    $settings->injectScript = true;

    expect(cachedDeliveryApplies($settings))->toBeFalse();
});

test('a site placing the script itself still counts server-side', function() {
    // With injectScript off we never issue a nonce, so its absence says
    // nothing at all about whether this page was cached.
    $settings = new Settings();
    $settings->trackingMode = Settings::TRACKING_MODE_HYBRID;
    $settings->injectScript = false;

    expect(cachedDeliveryApplies($settings))->toBeFalse();
});

/**
 * Mirrors CaptureService::isCachedDelivery(), which is private because
 * nothing outside capture has any business asking.
 */
function cachedDeliveryApplies(Settings $settings): bool
{
    $method = new ReflectionMethod(
        \coyshdigital\craftanalytics\ingest\CaptureService::class,
        'isCachedDelivery',
    );

    $capture = new \coyshdigital\craftanalytics\ingest\CaptureService();
    $capture->settings = $settings;

    return $method->invoke($capture);
}

/**
 * The nonce alone was never quite enough to say "this came from a cache".
 *
 * It is issued while a page is built, so its absence usually does mean the
 * HTML came from somewhere else - but it is also absent when a template ran
 * and the tag could not be placed. Craft fires the hook the tag is written
 * from only where its compiler found a literal `</body>` in the template
 * text, and a partial returning text/html, or a layout whose closing tag
 * comes from a variable, has none.
 *
 * Those pages got no tag, no nonce, and therefore no beacon either - and
 * capture read the missing nonce as a cache hit and stood aside for a beacon
 * that was never shipped. The view was counted by nobody, on both halves of
 * the pipeline at once, with nothing on any screen to say so.
 *
 * The render marker is the missing half: templates do not run for a cache
 * hit, so anything that marks one rules a cache hit out.
 */
test('a template that rendered without a closing body tag is still counted', function() {
    $injector = new coyshdigital\craftanalytics\ingest\ScriptInjector();

    expect($injector->renderedPageTemplate())->toBeFalse();

    // What Craft's afterRenderPageTemplate event does.
    $injector->markPageRendered();

    expect($injector->renderedPageTemplate())->toBeTrue()
        // No nonce, because there was nowhere to put the tag - but a template
        // did run, so this is not a cache hit and the server must count it.
        ->and($injector->getPendingNonce())->toBeNull();
});

test('a cache hit leaves the render unmarked', function() {
    // Templates do not run for a page served from a cache, so nothing marks
    // it and the nonce is absent for the reason capture assumes.
    $injector = new coyshdigital\craftanalytics\ingest\ScriptInjector();

    expect($injector->renderedPageTemplate())->toBeFalse()
        ->and($injector->getPendingNonce())->toBeNull();
});
