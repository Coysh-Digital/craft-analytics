<?php

namespace coyshdigital\craftanalytics\assets;

use craft\web\AssetBundle;
use craft\web\assets\cp\CpAsset as CraftCpAsset;

/**
 * The control panel's stylesheet. First-party, no CDN (C7).
 */
class CpAsset extends AssetBundle
{
    public function init(): void
    {
        $this->sourcePath = __DIR__ . '/../resources/css';
        $this->depends = [CraftCpAsset::class];
        $this->css = ['cp.css'];

        parent::init();
    }
}
