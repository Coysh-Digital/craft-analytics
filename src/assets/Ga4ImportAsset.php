<?php

namespace coyshdigital\craftanalytics\assets;

use craft\web\AssetBundle;
use craft\web\assets\cp\CpAsset as CraftCpAsset;

/**
 * The GA4 import utility's script: copy the redirect address, load the property
 * list, wire up the form. First-party, no CDN (C7).
 *
 * Its own directory, deliberately: the front-end scripts in resources/js are
 * published a file at a time (getPublishedUrl), which creates the shared hash
 * directory with only that one file in it. A directory bundle pointing at the
 * same folder would then be skipped as "already published", and its script
 * would 404. Keeping this here means nothing else has published the directory
 * first.
 */
class Ga4ImportAsset extends AssetBundle
{
    public function init(): void
    {
        $this->sourcePath = __DIR__ . '/../resources/cp/ga4';
        $this->depends = [CraftCpAsset::class];
        $this->js = ['ga4-import.js'];

        parent::init();
    }
}
