/**
 * Craft Analytics — Klaro adapter.
 *
 * Optional. Include it after consent.js and after Klaro, and add a service
 * named `craft-analytics` to your Klaro config:
 *
 *   { name: 'craft-analytics', purposes: ['analytics'], cookies: ['_ca_vid'] }
 *
 * Written against Klaro's public watcher API; no Klaro code is included here.
 */
(function(window) {
    'use strict';

    var SERVICE = 'craft-analytics';

    function relay(consents) {
        if (!window.craftAnalytics || !consents) {
            return;
        }

        window.craftAnalytics.consent(consents[SERVICE] ? 'granted' : 'denied', 'cmp');
    }

    function attach() {
        var manager = window.klaro && window.klaro.getManager && window.klaro.getManager();

        if (!manager) {
            return false;
        }

        // Klaro remembers a previous answer; relay it rather than waiting for
        // the visitor to answer a question they already answered.
        if (manager.confirmed) {
            relay(manager.consents);
        }

        manager.watch({
            update: function(_manager, eventType, data) {
                if (eventType === 'consents' || eventType === 'saveConsents') {
                    relay(data.consents || _manager.consents);
                }
            },
        });

        return true;
    }

    if (!attach()) {
        // Klaro may not have booted yet.
        window.addEventListener('DOMContentLoaded', attach);
    }
})(window);
