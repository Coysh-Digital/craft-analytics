<?php

namespace coyshdigital\craftanalytics\assets;

use craft\web\AssetBundle;
use craft\web\assets\cp\CpAsset as CraftCpAsset;

/**
 * The world map on the Locations report. Vendored locally, not from a CDN
 * (C7) - jsvectormap (MIT), plus its `world-merc` map data. Registered only
 * on the Locations screen, not plugin-wide, since nothing else uses it.
 *
 * The initialiser (`geo-map.js`) is a published file rather than an inline
 * `{% js %}` block on purpose: Cloudflare Rocket Loader and similar optimisers
 * defer inline scripts and routinely stop them running on an authenticated
 * control panel, leaving the map library loaded but never drawn. External
 * files are left alone. It is listed last so jsvectormap and its map data are
 * defined before it runs.
 */
class GeoMapAsset extends AssetBundle
{
    public function init(): void
    {
        $this->sourcePath = __DIR__ . '/../resources/cp/js';
        $this->depends = [CraftCpAsset::class];
        $this->css = ['vendor/jsvectormap.min.css'];
        $this->js = ['vendor/jsvectormap.min.js', 'vendor/world-merc.js', 'geo-map.js'];

        parent::init();
    }
}
