/**
 * Craft Analytics — consent API (Pro).
 *
 * Loaded only when consented tracking is switched on, so Lite sites and
 * cookieless Pro sites pay nothing for it and tracker.js stays small.
 *
 * Exposes:
 *
 *   craftAnalytics.consent('granted' | 'denied' | 'withdrawn')
 *   craftAnalytics.gpcDetected   — true when the browser has opted out
 *
 * The server owns the decision. This file only relays it: the cookie is set
 * server-side, HttpOnly and signed, because a consented visitor ID that page
 * scripts can read or forge would be worse than not having one.
 *
 * CMP adapters (adapters/*.js) call consent() with their own method name.
 * They are optional, separate files — nothing here depends on them.
 */
(function(window, document) {
    'use strict';

    var script = document.currentScript;

    if (!script || !window.navigator) {
        return;
    }

    var endpoint = script.getAttribute('data-consent-endpoint');

    if (!endpoint) {
        return;
    }

    var api = window.craftAnalytics = window.craftAnalytics || {};

    // A browser-level opt-out. Exposed so the site can skip showing a banner
    // to somebody who has already answered — the plugin refuses Tier-2 for
    // these visitors regardless of what any CMP later says.
    api.gpcDetected = navigator.globalPrivacyControl === true;

    var VALID = { granted: 1, denied: 1, withdrawn: 1 };
    var last = null;

    /**
     * Records a consent decision.
     *
     * @param {'granted'|'denied'|'withdrawn'} state
     * @param {string} [method] where the decision came from, for the evidence log
     */
    api.consent = function(state, method) {
        if (!VALID[state]) {
            return;
        }

        // CMPs re-announce their state on every page; only changes are worth
        // a request and a row in the log.
        var signature = state + ':' + (method || 'js');

        if (signature === last) {
            return;
        }

        last = signature;

        var body = new URLSearchParams();
        body.set('state', state);
        body.set('method', method || 'js');

        if (navigator.sendBeacon) {
            navigator.sendBeacon(endpoint, body);
        } else {
            fetch(endpoint, { method: 'POST', body: body, keepalive: true, credentials: 'same-origin' })
                .catch(function() { /* a lost consent ping must not break the page */ });
        }
    };

    // Anything queued before this file loaded — craftAnalytics.consent() may
    // be called by a CMP that fires earlier than we do.
    if (Array.isArray(api.q)) {
        api.q.forEach(function(args) {
            api.consent.apply(null, args);
        });
        api.q = [];
    }
})(window, document);
