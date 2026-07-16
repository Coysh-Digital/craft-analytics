/**
 * Craft Analytics — Cookiebot adapter.
 *
 * Optional. Include it after consent.js. Maps Cookiebot's `statistics`
 * category onto our consent, which is the category analytics belongs in.
 *
 * Written against Cookiebot's documented events; no Cookiebot code here.
 */
(function(window) {
    'use strict';

    function relay() {
        var cb = window.Cookiebot;

        if (!window.craftAnalytics || !cb || !cb.consent) {
            return;
        }

        window.craftAnalytics.consent(cb.consent.statistics ? 'granted' : 'denied', 'cmp');
    }

    // Fires on accept, decline, and on later changes of mind.
    window.addEventListener('CookiebotOnAccept', relay);
    window.addEventListener('CookiebotOnDecline', relay);

    // Already answered on a previous page.
    if (window.Cookiebot && window.Cookiebot.consent) {
        relay();
    } else {
        window.addEventListener('CookiebotOnLoad', relay);
    }
})(window);
