/**
 * Craft Analytics — Civic Cookie Control adapter.
 *
 * Optional. Include it after consent.js, and add an `analytics` category to
 * your Civic configuration with onAccept/onRevoke hooks, or rely on the
 * polling fallback below.
 *
 * Written against Civic's documented CookieControl API.
 */
(function(window) {
    'use strict';

    var CATEGORY = 'analytics';
    var lastSeen = null;

    function currentState() {
        var cc = window.CookieControl;

        if (!cc || !cc.getCategoryConsent) {
            return null;
        }

        try {
            return cc.getCategoryConsent(CATEGORY) ? 'granted' : 'denied';
        } catch (e) {
            // Civic throws if the category isn't configured; that is a site
            // configuration problem, not something to crash the page over.
            return null;
        }
    }

    function relay() {
        var state = currentState();

        if (state === null || state === lastSeen) {
            return;
        }

        lastSeen = state;

        if (window.craftAnalytics) {
            window.craftAnalytics.consent(state, 'cmp');
        }
    }

    // Civic has no single reliable change event across versions, so watch the
    // state briefly after load and after any interaction.
    window.addEventListener('DOMContentLoaded', relay);
    window.addEventListener('click', relay, true);

    var checks = 0;
    var timer = setInterval(function() {
        relay();

        if (++checks > 20) {
            clearInterval(timer);
        }
    }, 500);
})(window);
