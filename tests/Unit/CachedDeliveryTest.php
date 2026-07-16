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
