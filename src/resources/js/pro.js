/**
 * Craft Analytics — Pro tracking: events, outbound links, downloads and
 * scroll depth.
 *
 * A separate file, loaded only on Pro sites with these features on, so
 * tracker.js stays at 1.2 KB for everyone else (C3).
 *
 * Scroll depth rides the pageview beacon rather than sending its own: it is a
 * property of the pageview, and one request per pageview is the budget. Clicks
 * are genuinely separate events and each sends its own beacon — there is no
 * way to report a click on the way out of the page it left.
 *
 * Exposes:
 *
 *   craftAnalytics.event(name, { value: 12.5, path: '/x' })
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
        if (typeof name !== 'string' || !name) {
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
