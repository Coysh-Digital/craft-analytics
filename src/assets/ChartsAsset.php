<?php

namespace coyshdigital\craftanalytics\assets;

use craft\web\AssetBundle;
use craft\web\assets\cp\CpAsset as CraftCpAsset;

/**
 * Chart.js and the one initialiser that drives every chart in the control
 * panel. Vendored locally, not from a CDN (C7) - see
 * `src/resources/cp/js/vendor/PROVENANCE.md` for the pinned version and
 * checksums.
 *
 * `ca-charts.js` is a published file rather than an inline `{% js %}` block for
 * the same reason `geo-map.js` is: Cloudflare Rocket Loader and similar
 * optimisers rewrite inline scripts to `type="text/rocketscript"` and defer
 * them, which on an authenticated control panel routinely means they never run
 * at all. The library would load and the charts would simply never be drawn,
 * with nothing in the console, because the code that reports the failure is
 * itself the code that never ran. It is listed last so Chart.js is defined
 * before it runs.
 *
 * Depending on the plugin's own CpAsset is load-bearing rather than tidy:
 * `ca-charts.js` resolves every series colour by reading a `--ca-*` custom
 * property off the page, and those are declared in `cp.css`.
 *
 * Registered per action - see BaseCpController::registerCharts() - so screens
 * that draw nothing do not pay for it. It must never be registered from
 * commonVariables(), nor on Craft's own entry editor or dashboard, where the
 * sparklines are deliberately still server-rendered SVG.
 */
class ChartsAsset extends AssetBundle
{
    public function init(): void
    {
        $this->sourcePath = __DIR__ . '/../resources/cp/js';
        $this->depends = [CraftCpAsset::class, CpAsset::class];
        $this->js = ['vendor/chart.umd.js', 'ca-charts.js'];

        parent::init();
    }
}
