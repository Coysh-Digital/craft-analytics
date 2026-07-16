/**
 * Craft Analytics — CookieYes adapter.
 *
 * Optional. Include it after consent.js. Maps CookieYes's `analytics`
 * category onto our consent.
 *
 * Written against CookieYes's documented `cookieyes_consent_update` event.
 */
(function(window, document) {
    'use strict';

    function relay(detail) {
        var categories = (detail && detail.accepted) || [];

        if (!window.craftAnalytics) {
            return;
        }

        var granted = categories.indexOf('analytics') !== -1;
        window.craftAnalytics.consent(granted ? 'granted' : 'denied', 'cmp');
    }

    document.addEventListener('cookieyes_consent_update', function(event) {
        relay(event.detail);
    });

    // Already answered before this script ran.
    if (window.getCkyConsent) {
        var consent = window.getCkyConsent();

        if (consent && consent.isUserActionCompleted) {
            window.craftAnalytics.consent(
                consent.categories && consent.categories.analytics ? 'granted' : 'denied',
                'cmp',
            );
        }
    }
})(window, document);
