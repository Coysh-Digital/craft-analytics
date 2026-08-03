/**
 * Draws every Chart.js chart in the control panel.
 *
 * One file drives N charts on a page. A template emits a `[data-ca-chart]`
 * figure with a `type="application/json"` block beside it; this finds each one,
 * looks the type up in BUILDERS, and draws it. Adding a chart to a screen is
 * therefore no JavaScript at all, and adding a new *kind* of chart is one entry
 * in BUILDERS.
 *
 * Two rules hold this together, and both matter more than they look:
 *
 * 1. The payload is data, never Chart.js configuration. Templates emit
 *    labels, datasets and a few plain options; every Chart.js option name
 *    lives in this file. A Chart.js upgrade then touches one file rather than
 *    every template that happens to draw something.
 *
 * 2. No colour crosses the PHP/JS boundary. Datasets carry a *token*
 *    ('views', 'series-3'), resolved here against the `--ca-*` custom
 *    properties in cp.css. That is what stops the palette being duplicated
 *    into templates the way #2563eb once was, in four places at once.
 *
 * Published rather than inline for the Rocket Loader reason documented on
 * ChartsAsset - an inline initialiser on an authenticated CP frequently never
 * runs, and reports nothing when it doesn't.
 */
(function () {
    var figures = document.querySelectorAll('[data-ca-chart]');

    if (!figures.length) {
        return;
    }

    var PREFIX = 'craft-analytics: ';
    var charts = [];

    /**
     * Hides the canvas, shows the server-rendered note, and un-hides the data
     * table that every chart ships with.
     *
     * Worth being deliberate about: the accessible table is already in the DOM
     * for screen readers, so a failure here can surface the actual numbers
     * rather than an apology. A chart that cannot draw is then a formatting
     * problem, not a missing report.
     */
    function giveUp(figure, id, reason) {
        figure.hidden = true;

        var fallback = document.getElementById(id + '-fallback');
        if (fallback) {
            fallback.hidden = false;
        }

        var table = document.getElementById(id + '-table');
        if (table) {
            table.classList.remove('ca-visually-hidden-table');
        }

        if (window.console) {
            console.warn(PREFIX + id + ' could not be drawn - ' + reason);
        }
    }

    function giveUpAll(reason) {
        Array.prototype.forEach.call(figures, function (figure) {
            giveUp(figure, figure.getAttribute('data-ca-chart'), reason);
        });
    }

    if (typeof Chart === 'undefined' || typeof Chart.defaults === 'undefined') {
        return giveUpAll('chart.umd.js did not load.');
    }

    // ------------------------------------------------------------- colour

    /**
     * Resolves a colour token against the page. Reading from the figure rather
     * than :root means the `.ca` scope's custom properties win, and that a
     * future CP theme changing them is picked up with no change here.
     */
    function token(figure, name, fallback) {
        var value = getComputedStyle(figure).getPropertyValue('--ca-' + name);
        value = value ? value.trim() : '';

        // Reaching the last fallback means cp.css did not load, so every
        // series would be this colour. A flat grey chart is a legible symptom;
        // repeating a brand colour here would just be a quieter copy of the
        // duplication this function exists to remove.
        return value || fallback || '#888888';
    }

    /**
     * Chart.js wants a fill colour, and the tokens are opaque. Drawing to a
     * scratch canvas is the only way to turn an arbitrary CSS colour - which
     * may be a hex, an rgb(), a var() chain or a color-mix() - into something
     * we can add an alpha to without parsing CSS by hand.
     */
    function fade(colour, alpha) {
        var probe = document.createElement('canvas').getContext('2d');
        probe.fillStyle = '#000';
        probe.fillStyle = colour;

        var resolved = probe.fillStyle;

        if (resolved.charAt(0) === '#' && resolved.length === 7) {
            return 'rgba(' + parseInt(resolved.substr(1, 2), 16) + ','
                + parseInt(resolved.substr(3, 2), 16) + ','
                + parseInt(resolved.substr(5, 2), 16) + ',' + alpha + ')';
        }

        // Already a functional notation; let the browser deal with it.
        return resolved.replace(/^rgb\(/, 'rgba(').replace(/\)$/, ', ' + alpha + ')');
    }

    // ------------------------------------------------------------- format

    /**
     * Mirrors the `ca.compact` Twig macro, so an axis and the table beneath it
     * abbreviate the same number the same way.
     */
    function compact(value, locale) {
        var abs = Math.abs(value);

        if (abs >= 1000000) {
            return trimZero(value / 1000000) + 'M';
        }

        if (abs >= 1000) {
            return trimZero(value / 1000) + 'k';
        }

        return value.toLocaleString(locale);
    }

    function trimZero(value) {
        return value.toFixed(1).replace(/\.0$/, '');
    }

    function count(value, locale) {
        return Number(value).toLocaleString(locale);
    }

    // ------------------------------------------------------------ options

    var reduceMotion = window.matchMedia
        && window.matchMedia('(prefers-reduced-motion: reduce)').matches;

    Chart.defaults.font.family = getComputedStyle(document.body).fontFamily;
    Chart.defaults.font.size = 11;
    Chart.defaults.maintainAspectRatio = false;
    Chart.defaults.responsive = true;

    if (reduceMotion) {
        Chart.defaults.animation = false;
        Chart.defaults.animations = false;
        Chart.defaults.transitions.active.animation.duration = 0;
    }

    /**
     * The legend is server-rendered HTML on every screen that has one. Chart.js
     * draws its own onto the canvas, where a screen reader cannot reach it and
     * where series identity would rest on colour alone - so it stays off.
     */
    function baseOptions(figure, payload) {
        var locale = payload.locale || undefined;

        return {
            plugins: {
                legend: { display: false },
                tooltip: {
                    backgroundColor: token(figure, 'tooltip-bg', '#1f2937'),
                    titleColor: token(figure, 'tooltip-fg', '#ffffff'),
                    bodyColor: token(figure, 'tooltip-fg', '#ffffff'),
                    padding: 10,
                    displayColors: true,
                    boxWidth: 8,
                    boxHeight: 8,
                    usePointStyle: true,
                    callbacks: {
                        label: function (context) {
                            // Names the series in the tooltip as well as
                            // colouring it, so identity never rests on colour.
                            var value = context.parsed.y !== undefined && context.parsed.y !== null
                                ? context.parsed.y
                                : context.parsed;

                            return ' ' + context.dataset.label + ': ' + count(value, locale);
                        },
                    },
                },
            },
        };
    }

    function scaleOptions(figure, payload, stacked) {
        var locale = payload.locale || undefined;
        var options = payload.options || {};

        return {
            x: {
                type: 'category',
                stacked: !!stacked,
                grid: { display: false },
                border: { color: token(figure, 'grid', '#e5e7eb') },
                ticks: {
                    color: token(figure, 'axis', '#6b7280'),
                    autoSkip: true,
                    maxTicksLimit: options.maxTicks || 8,
                    maxRotation: 0,
                },
            },
            y: {
                beginAtZero: true,
                stacked: !!stacked,
                border: { display: false },
                grid: { color: token(figure, 'grid', '#e5e7eb') },
                ticks: {
                    color: token(figure, 'axis', '#6b7280'),
                    maxTicksLimit: 6,
                    callback: function (value) {
                        return compact(value, locale);
                    },
                },
            },
        };
    }

    // ----------------------------------------------------------- builders

    function lineDatasets(figure, payload, stacked) {
        return (payload.datasets || []).map(function (dataset, index) {
            var colour = token(figure, dataset.token || ('series-' + (index + 1)));

            return {
                label: dataset.label,
                data: dataset.data,
                borderColor: colour,
                backgroundColor: fade(colour, stacked ? 0.55 : 0.14),
                // Stacked areas fill to the series below; an ordinary line
                // fills to the axis, and only if it asked to.
                fill: stacked ? (index === 0 ? 'origin' : '-1') : (dataset.fill ? 'origin' : false),
                borderWidth: 2,
                pointRadius: 0,
                pointHoverRadius: 4,
                pointBackgroundColor: colour,
                pointHoverBorderColor: colour,
                tension: 0.25,
            };
        });
    }

    function buildLine(figure, payload) {
        return {
            type: 'line',
            data: { labels: payload.labels, datasets: lineDatasets(figure, payload, false) },
            options: Object.assign(baseOptions(figure, payload), {
                interaction: { mode: 'index', intersect: false },
                scales: scaleOptions(figure, payload, false),
            }),
        };
    }

    function buildStackedArea(figure, payload) {
        return {
            type: 'line',
            data: { labels: payload.labels, datasets: lineDatasets(figure, payload, true) },
            options: Object.assign(baseOptions(figure, payload), {
                interaction: { mode: 'index', intersect: false },
                scales: scaleOptions(figure, payload, true),
            }),
        };
    }

    function buildDoughnut(figure, payload) {
        var dataset = (payload.datasets || [])[0] || { data: [] };
        var locale = payload.locale || undefined;

        var colours = dataset.data.map(function (value, index) {
            return token(figure, 'series-' + ((index % 6) + 1));
        });

        var options = Object.assign(baseOptions(figure, payload), {
            cutout: '62%',
            interaction: { mode: 'nearest', intersect: true },
        });

        // Shares come from PHP so the tooltip and the table's Share column
        // cannot round differently.
        options.plugins.tooltip.callbacks.label = function (context) {
            var share = (payload.shares || [])[context.dataIndex];
            var text = ' ' + context.label + ': ' + count(context.parsed, locale);

            return share === undefined ? text : text + ' (' + share.toFixed(1) + '%)';
        };

        return {
            type: 'doughnut',
            data: {
                labels: payload.labels,
                datasets: [{
                    label: dataset.label || '',
                    data: dataset.data,
                    backgroundColor: colours,
                    borderColor: token(figure, 'surface', '#ffffff'),
                    borderWidth: 2,
                }],
            },
            options: options,
        };
    }

    var BUILDERS = {
        line: buildLine,
        stackedArea: buildStackedArea,
        doughnut: buildDoughnut,
    };

    // ---------------------------------------------------------------- draw

    function draw(figure) {
        var id = figure.getAttribute('data-ca-chart');
        var canvas = document.getElementById(id);

        if (!canvas) {
            return;
        }

        var dataEl = document.getElementById(id + '-data');

        // The data block is rendered inside the figure, so a missing one means
        // something stripped it rather than that there is nothing to draw.
        if (!dataEl) {
            return giveUp(figure, id, 'the ' + id + '-data block is not on the page.');
        }

        var payload;

        try {
            payload = JSON.parse(dataEl.textContent || '{}');
        } catch (e) {
            return giveUp(figure, id, 'the chart data could not be parsed.');
        }

        var build = BUILDERS[payload.type];

        if (!build) {
            return giveUp(figure, id, 'unknown chart type "' + payload.type + '".');
        }

        try {
            charts.push({
                figure: figure,
                id: id,
                instance: new Chart(canvas.getContext('2d'), build(figure, payload)),
            });
        } catch (e) {
            giveUp(figure, id, e && e.message ? e.message : 'Chart.js threw.');
        }
    }

    Array.prototype.forEach.call(figures, draw);

    // Craft 5 has no control panel dark mode yet. When it gets one, every
    // colour here already comes from a custom property and will follow it - but
    // only for charts drawn after the change, since Chart.js bakes colours into
    // the canvas at construction. Redrawing on the switch is what makes the
    // tokens actually pay off. Dormant today, correct the day it isn't.
    if (window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').addEventListener) {
        window.matchMedia('(prefers-color-scheme: dark)').addEventListener('change', function () {
            var redrawing = charts.splice(0, charts.length);

            redrawing.forEach(function (chart) {
                chart.instance.destroy();
                draw(chart.figure);
            });
        });
    }
})();
