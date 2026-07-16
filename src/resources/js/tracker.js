/**
 * Craft Analytics tracker.
 *
 * Sends one request per pageview, on the way out. No cookies, no storage, no
 * identifiers, no dependencies — it reads nothing from the device and leaves
 * nothing behind.
 *
 * What it is for: the server cannot see pageviews that PHP never handled
 * (Blitz, Varnish, Cloudflare, bfcache), and cannot know how long anyone
 * stayed. This fills exactly those gaps.
 *
 * The nonce is the deduplication key. When PHP rendered this page it recorded
 * the nonce server-side and counted the view; this beacon then claims that
 * nonce and only contributes dwell time. When the page came from a cache, the
 * nonce is stale or already claimed and the server counts the view from this
 * beacon instead. Nothing here has to know which happened — the server
 * decides.
 */
(function(window, document) {
    'use strict';

    var script = document.currentScript;

    // No sendBeacon means an ancient browser: do nothing rather than degrade
    // the page for someone whose visit we cannot measure anyway.
    if (!script || !window.navigator || !navigator.sendBeacon) {
        return;
    }

    var endpoint = script.getAttribute('data-endpoint');

    if (!endpoint) {
        return;
    }

    var nonce = script.getAttribute('data-nonce') || '';
    var started = clock();
    var sent = false;
    var extras = [];

    var api = window.craftAnalytics = window.craftAnalytics || {};

    /**
     * Lets the Pro tracker add fields to this pageview's beacon — scroll
     * depth, say — instead of sending a second request for them. One request
     * per pageview is the budget, and it stays the budget.
     *
     * @param {function(): Object} fn returns extra params at send time
     */
    api.extend = function(fn) {
        if (typeof fn === 'function') {
            extras.push(fn);
        }
    };

    function clock() {
        return window.performance && performance.now ? performance.now() : Date.now();
    }

    function send() {
        if (sent) {
            return;
        }

        sent = true;

        var body = new URLSearchParams();
        body.set('p', location.pathname + location.search);
        body.set('d', String(Math.round(clock() - started)));

        if (nonce) {
            body.set('n', nonce);
        }

        for (var i = 0; i < extras.length; i++) {
            try {
                var fields = extras[i]() || {};

                for (var key in fields) {
                    if (Object.prototype.hasOwnProperty.call(fields, key)) {
                        body.set(key, String(fields[key]));
                    }
                }
            } catch (e) {
                // An extension that throws must not cost us the pageview.
            }
        }

        navigator.sendBeacon(endpoint, body);
    }

    // pagehide fires on navigation and tab close, and is the only one of the
    // two that iOS reliably delivers. visibilitychange covers tab switches
    // where pagehide never fires at all.
    addEventListener('pagehide', send);

    addEventListener('visibilitychange', function() {
        if (document.visibilityState === 'hidden') {
            send();
        }
    });

    // Restored from the back/forward cache: PHP did not run, so this is a
    // pageview nobody has counted. Re-arm, and drop the nonce — it belongs to
    // the original delivery of this page, not to this one.
    addEventListener('pageshow', function(event) {
        if (event.persisted) {
            sent = false;
            nonce = '';
            started = clock();
        }
    });
})(window, document);
