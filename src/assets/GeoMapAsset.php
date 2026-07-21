<?php

namespace coyshdigital\craftanalytics\assets;

use craft\web\AssetBundle;
use craft\web\assets\cp\CpAsset as CraftCpAsset;

/**
 * The world map on the Locations report. Vendored locally, not from a CDN
 * (C7) - jsvectormap (MIT), plus its `world-merc` map data. Registered only
 * on the Locations screen, not plugin-wide, since nothing else uses it.
 */
class GeoMapAsset extends AssetBundle
{
    public function init(): void
    {
        $this->sourcePath = __DIR__ . '/../resources/cp/js/vendor';
        $this->depends = [CraftCpAsset::class];
        $this->css = ['jsvectormap.min.css'];
        $this->js = ['jsvectormap.min.js', 'world-merc.js'];

        parent::init();
    }
}
