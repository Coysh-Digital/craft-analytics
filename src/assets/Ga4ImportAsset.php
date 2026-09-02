<?php

namespace coyshdigital\craftanalytics\assets;

use craft\web\AssetBundle;
use craft\web\assets\cp\CpAsset as CraftCpAsset;

/**
 * The GA4 import utility's script: copy the redirect address, load the property
 * list, wire up the form. First-party, no CDN (C7).
 */
class Ga4ImportAsset extends AssetBundle
{
    public function init(): void
    {
        $this->sourcePath = __DIR__ . '/../resources/js';
        $this->depends = [CraftCpAsset::class];
        $this->js = ['ga4-import.js'];

        parent::init();
    }
}
