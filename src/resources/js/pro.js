/**
 * Craft Analytics — Pro tracking: events, outbound links, downloads and
 * scroll depth.
 *
 * A separate file, loaded only on Pro sites with these features on, so
 * tracker.js stays small for everyone else (C3).
 *
 * Scroll depth rides the tracker's engagement beacon rather than sending a
 * request of its own: it is a property of the pageview, and it is only known
 * once the visitor leaves — which is exactly when that beacon goes. Clicks are
 * genuinely separate events and each sends its own, because there is no way to
 * report a click on the way out of the page it left.
 *
 * Segments live here too, for the same reason: they are a Pro feature, and
 * tracker.js is what every visitor to every site downloads. They ride the
 * engagement beacon as well — a segment describes the whole visit, so it does
 * not matter which of a session's beacons carries it.
 *
 * Exposes:
 *
 *   craftAnalytics.event(name, { value: 12.5, path: '/x' })
 *   craftAnalytics.segment('plan', 'pro')
 */
(function(window, document) {
    'use strict';

    var script = document.currentScript;

    if (!script || !window.navigator || !navigator.sendBeacon) {
        return;
    }

    var endpoint = script.getAttribute('data-endpoint');

    if (!endpoint) {
        return;
    }

    var api = window.craftAnalytics = window.craftAnalytics || {};
    var trackEvents = script.getAttribute('data-events') === '1';
    var trackOutbound = script.getAttribute('data-outbound') === '1';
    var trackDownloads = script.getAttribute('data-downloads') === '1';
    var trackScroll = script.getAttribute('data-scroll') === '1';
    var extensions = (script.getAttribute('data-extensions') || '').split(',').filter(Boolean);

    function send(params) {
        var body = new URLSearchParams();
        body.set('p', location.pathname + location.search);

        Object.keys(params).forEach(function(key) {
            if (params[key] !== null && params[key] !== undefined && params[key] !== '') {
                body.set(key, String(params[key]));
            }
        });

        navigator.sendBeacon(endpoint, body);
    }

    /**
     * A custom event.
     *
     * @param {string} name
     * @param {{value?: number, path?: string}} [options]
     */
    api.event = function(name, options) {
        if (!trackEvents || typeof name !== 'string' || !name) {
            return;
        }

        options = options || {};

        send({
            k: 'event',
            en: name.slice(0, 120),
            ev: typeof options.value === 'number' ? options.value : null,
            p: options.path || location.pathname + location.search,
        });
    };

    // ----------------------------------------------------------- segments

    var segments = {};

    /**
     * Tags this visit with something the site knows about it — the plan they
     * are on, the role they hold.
     *
     * The server keeps only the segments the site declared in PHP, so this
     * cannot invent a dimension. It exists because a page served from a cache
     * is one PHP never saw, and could not work the answer out for.
     *
     * This script is deferred, so window.craftAnalyticsSegments lets an inline
     * script tag a visit before it has loaded. Both are read when the beacon
     * is sent, so either is in time.
     *
     * @param {string} key
     * @param {string|number|boolean} value
     */
    api.segment = function(key, value) {
        if (typeof key === 'string' && value !== null && value !== undefined) {
            segments[key] = String(value);
        }
    };

    // Rides the engagement beacon rather than sending its own request. A
    // segment describes the visit, and the session takes the first value it
    // is given for a key, so it does not matter which beacon brings it.
    if (typeof api.extend === 'function') {
        api.extend(function() {
            var all = {};
            var empty = true;
            var key;

            for (key in window.craftAnalyticsSegments) {
                all[key] = String(window.craftAnalyticsSegments[key]);
                empty = false;
            }

            for (key in segments) {
                all[key] = segments[key];
                empty = false;
            }

            return empty ? {} : { sg: JSON.stringify(all) };
        });
    }

    // ------------------------------------------------------------- scroll

    if (trackScroll) {
        var deepest = 0;

        function depth() {
            var doc = document.documentElement;
            var scrollable = doc.scrollHeight - window.innerHeight;

            // A page that doesn't scroll was, by definition, read to the end.
            if (scrollable <= 0) {
                return 100;
            }

            return Math.min(100, Math.round((window.scrollY / scrollable) * 100));
        }

        function bucket(percent) {
            if (percent >= 100) return 100;
            if (percent >= 75) return 75;
            if (percent >= 50) return 50;
            if (percent >= 25) return 25;
            return 0;
        }

        addEventListener('scroll', function() {
            var current = depth();

            if (current > deepest) {
                deepest = current;
            }
        }, { passive: true });

        // Contribute to the pageview beacon rather than sending our own.
        if (typeof api.extend === 'function') {
            api.extend(function() {
                var reached = bucket(Math.max(deepest, depth()));

                return reached > 0 ? { s: reached } : {};
            });
        }
    }

    // -------------------------------------------------- outbound/downloads

    function isDownload(url) {
        var path = url.pathname.toLowerCase();
        var dot = path.lastIndexOf('.');

        if (dot === -1) {
            return false;
        }

        return extensions.indexOf(path.slice(dot + 1)) !== -1;
    }

    if (trackOutbound || trackDownloads) {
        // Capture phase, so a click is recorded even when the page's own
        // handlers stop propagation or navigate away.
        addEventListener('click', function(event) {
            var anchor = event.target && event.target.closest && event.target.closest('a[href]');

            if (!anchor) {
                return;
            }

            var url;

            try {
                url = new URL(anchor.href, location.href);
            } catch (e) {
                return;
            }

            if (url.protocol !== 'http:' && url.protocol !== 'https:') {
                return;
            }

            var external = url.host !== location.host;

            if (external && trackOutbound) {
                send({ k: 'outbound', t: url.href.slice(0, 500) });
            } else if (!external && trackDownloads && isDownload(url)) {
                send({ k: 'download', t: url.href.slice(0, 500) });
            }
        }, true);
    }
})(window, document);
