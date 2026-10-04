<?php

declare(strict_types=1);

namespace justinholtweb\publishr\web\assets\cp;

use craft\web\AssetBundle;
use craft\web\assets\cp\CpAsset as CraftCpAsset;
use craft\web\View;

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

    public function registerAssetFiles($view): void
    {
        parent::registerAssetFiles($view);

        // Every string publishr.js passes to `Craft.t()`. Unregistered, they come out in English
        // whatever the editor's language is.
        if ($view instanceof View) {
            $view->registerTranslations('publishr', [
                'Couldn’t move that.',
                'Couldn’t assign that.',
                'Couldn’t save that.',
                'That didn’t work.',
                'Nothing selected.',
                'Move to',
                'Move',
                'Saved. Reload the page to see the panel’s new state.',
            ]);
        }
    }
}
