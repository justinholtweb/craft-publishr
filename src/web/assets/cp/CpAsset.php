<?php

declare(strict_types=1);

namespace justinholtweb\publishr\web\assets\cp;

use craft\web\AssetBundle;
use craft\web\assets\cp\CpAsset as CraftCpAsset;

class CpAsset extends AssetBundle
{
    public function init(): void
    {
        $this->sourcePath = __DIR__ . '/dist';
        $this->depends = [CraftCpAsset::class];
        $this->js = ['publishr.js'];
        $this->css = ['publishr.css'];

        parent::init();
    }
}
