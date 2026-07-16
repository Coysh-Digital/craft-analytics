/**
 * Craft Analytics — IAB TCF v2.2 adapter.
 *
 * Optional. Include it after consent.js, on sites that run a TCF-registered
 * CMP.
 *
 * Requires **both**:
 *   - Purpose 1  — store and/or access information on a device
 *   - Purpose 8  — measure content performance
 *
 * Both, because they are different permissions: purpose 8 without purpose 1
 * is consent to be measured but not to be given a cookie, which is Tier-1,
 * not Tier-2. Requiring both is the conservative reading, and the one that
 * matches what we would actually do with the consent.
 *
 * Written against the public __tcfapi surface; no IAB code is included.
 */
(function(window) {
    'use strict';

    var REQUIRED_PURPOSES = [1, 8];

    function granted(tcData) {
        var purposes = tcData && tcData.purpose && tcData.purpose.consents;

        if (!purposes) {
            return false;
        }

        return REQUIRED_PURPOSES.every(function(id) {
            return purposes[id] === true;
        });
    }

    function attach() {
        if (typeof window.__tcfapi !== 'function') {
            return false;
        }

        window.__tcfapi('addEventListener', 2, function(tcData, success) {
            if (!success || !tcData || !window.craftAnalytics) {
                return;
            }

            // Wait until the visitor has actually answered.
            if (tcData.eventStatus !== 'useractioncomplete' && tcData.eventStatus !== 'tcloaded') {
                return;
            }

            // gdprApplies === false means the framework has decided these
            // rules don't apply here; that is not an affirmative consent, so
            // it does not unlock Tier-2 on its own.
            if (tcData.gdprApplies === false) {
                return;
            }

            window.craftAnalytics.consent(granted(tcData) ? 'granted' : 'denied', 'tcf');
        });

        return true;
    }

    if (!attach()) {
        window.addEventListener('DOMContentLoaded', attach);
    }
})(window);
