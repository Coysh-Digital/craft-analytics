/**
 * Craft Analytics tracker.
 *
 * No cookies, no storage, no identifiers, no dependencies — it reads nothing
 * from the device and leaves nothing behind.
 *
 * What it is for: the server cannot see pageviews that PHP never handled
 * (Blitz, Varnish, Cloudflare, bfcache), and cannot know how long anyone
 * stayed. This fills exactly those gaps.
 *
 * It sends two requests per pageview, and the split matters:
 *
 *   1. On load, a pageview. The nonce decides whether it counts: when PHP
 *      rendered this page it recorded the nonce and counted the view, so this
 *      beacon claims the nonce and the server counts nothing. When the page
 *      came from a cache, the nonce is stale or already claimed and the server
 *      counts the view from this beacon instead. Nothing here has to know
 *      which happened.
 *   2. On the way out, the engagement: time on page and how far they read.
 *      Never a pageview - it cannot be, because the first request already was
 *      one.
 *
 * It used to be one request, sent on the way out, which was cheaper and wrong.
 * On a cached page nothing counted the view until the visitor left, so
 * Real-time could not show anyone who was still reading, and a visitor who
 * closed their laptop was never counted at all. A pageview has to be reported
 * when it happens.
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
    var leaving = false;
    var extras = [];

    var api = window.craftAnalytics = window.craftAnalytics || {};

    /**
     * Lets the Pro tracker add fields to the engagement beacon — scroll depth,
     * say — instead of sending a request of its own. Scroll depth is only
     * known once they leave, which is exactly when that beacon goes.
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

    function path() {
        return location.pathname + location.search;
    }

    /** The pageview, sent as it happens. */
    function view() {
        var body = new URLSearchParams();
        body.set('p', path());

        if (nonce) {
            body.set('n', nonce);
        }

        navigator.sendBeacon(endpoint, body);
    }

    /** Time on page and scroll depth, sent once, on the way out. */
    function engagement() {
        if (leaving) {
            return;
        }

        leaving = true;

        var body = new URLSearchParams();
        body.set('p', path());
        body.set('d', String(Math.round(clock() - started)));
        // Never counts a view: the load beacon already did.
        body.set('e', '1');

        for (var i = 0; i < extras.length; i++) {
            try {
                var fields = extras[i]() || {};

                for (var key in fields) {
                    if (Object.prototype.hasOwnProperty.call(fields, key)) {
                        body.set(key, String(fields[key]));
                    }
                }
            } catch (e) {
                // An extension that throws must not cost us the beacon.
            }
        }

        navigator.sendBeacon(endpoint, body);
    }

    view();

    // pagehide fires on navigation and tab close, and is the only one of the
    // two that iOS reliably delivers. visibilitychange covers tab switches
    // where pagehide never fires at all.
    addEventListener('pagehide', engagement);

    addEventListener('visibilitychange', function() {
        if (document.visibilityState === 'hidden') {
            engagement();
        }
    });

    // Restored from the back/forward cache: PHP did not run, so this is a
    // pageview nobody has counted. Drop the nonce — it belongs to the original
    // delivery of this page, not to this one — and report it as new.
    addEventListener('pageshow', function(event) {
        if (event.persisted) {
            leaving = false;
            nonce = '';
            started = clock();
            view();
        }
    });
})(window, document);
