/**
 * Craft Analytics — Osano adapter.
 *
 * Optional. Include it after consent.js. Maps Osano's ANALYTICS category onto
 * our consent.
 *
 * Written against Osano's documented cm.addEventListener API.
 */
(function(window) {
    'use strict';

    function relay(consent) {
        if (!window.craftAnalytics || !consent) {
            return;
        }

        window.craftAnalytics.consent(consent.ANALYTICS === 'ACCEPT' ? 'granted' : 'denied', 'cmp');
    }

    function attach() {
        var cm = window.Osano && window.Osano.cm;

        if (!cm) {
            return false;
        }

        if (cm.getConsent) {
            relay(cm.getConsent());
        }

        cm.addEventListener('osano-cm-consent-saved', relay);
        cm.addEventListener('osano-cm-consent-changed', relay);

        return true;
    }

    if (!attach()) {
        window.addEventListener('DOMContentLoaded', attach);
    }
})(window);
