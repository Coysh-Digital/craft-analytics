/**
 * Draws the world map on the Locations report.
 *
 * This deliberately lives in a published file rather than an inline
 * `{% js %}` block. Cloudflare Rocket Loader (and similar "optimisers")
 * rewrite inline scripts to `type="text/rocketscript"` and defer them, which
 * on an authenticated control panel routinely means they never run at all -
 * the map library and its data would load fine over the network and the
 * initialiser would simply never fire, leaving an empty card and no error.
 * An external script and a `type="application/json"` data block are both left
 * alone by Rocket Loader, so the map draws whether or not it is enabled.
 */
(function () {
    var el = document.getElementById('ca-geo-map');
    var fallback = document.getElementById('ca-geo-map-fallback');

    if (!el) {
        return;
    }

    function giveUp(reason) {
        el.hidden = true;
        if (fallback) {
            fallback.hidden = false;
        }
        // Says so out loud, so the next person to look at a blank card has
        // somewhere to start.
        if (window.console) {
            console.warn('craft-analytics: the Locations map could not be drawn - ' + reason);
        }
    }

    if (typeof jsVectorMap === 'undefined') {
        return giveUp('jsvectormap.min.js did not load.');
    }

    var payload;
    var dataEl = document.getElementById('ca-geo-data');

    // The data block is rendered next to the map itself, so a missing one
    // means something stripped it rather than that there is nothing to draw.
    // Better to say so than to draw an empty grey world and look deliberate.
    if (!dataEl) {
        return giveUp('the ca-geo-data block is not on the page.');
    }

    try {
        payload = JSON.parse(dataEl.textContent || '{}');
    } catch (e) {
        return giveUp('the map data could not be parsed.');
    }

    var values = payload.values || {};
    var sessionsLabel = payload.sessionsLabel || 'sessions';

    // jsvectormap's scale is a lookup table, not a gradient: it fetches
    // scale[value], so handing it session counts asks for scale[4213], gets
    // undefined, and leaves the country with no fill at all - which paints it
    // black, SVG's default. So the counts are bucketed here and the bucket is
    // what the map is given.
    //
    // Buckets start at 1 because setValues() skips falsy ones, and a country
    // in bucket 0 would go black for exactly the same reason.
    var palette = ['#dbe4fb', '#bfd0f7', '#93b4f2', '#5f8de9', '#3b6fe0', '#2563eb'];
    var scale = {};
    var buckets = {};
    var max = 0;
    var code;

    for (code in values) {
        if (values[code] > max) max = values[code];
    }

    for (var i = 0; i < palette.length; i++) {
        scale[i + 1] = palette[i];
    }

    for (code in values) {
        // Square-rooted, so one dominant country does not flatten everywhere
        // else into the palest shade.
        var share = max > 0 ? Math.sqrt(values[code] / max) : 0;
        buckets[code] = 1 + Math.round(share * (palette.length - 1));
    }

    try {
        new jsVectorMap({
            selector: '#ca-geo-map',
            map: 'world_merc',
            zoomButtons: false,
            showTooltip: true,
            series: {
                regions: [{
                    values: buckets,
                    scale: scale,
                }],
            },
            regionStyle: {
                initial: { fill: '#e5e7eb' },
            },
            onRegionTooltipShow: function (event, tooltip, code) {
                var sessions = values[code] || 0;
                tooltip.text(tooltip.text() + ': ' + sessions.toLocaleString() + ' ' + sessionsLabel);
            },
        });
    } catch (e) {
        // Most likely world-merc.js not loading, which leaves the library
        // present but the map data missing.
        giveUp(e && e.message ? e.message : 'the map data is unavailable.');
    }
})();
