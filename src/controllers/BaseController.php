<?php

declare(strict_types=1);

namespace justinholtweb\publishr\controllers;

use Craft;
use craft\web\Controller;
use justinholtweb\publishr\Plugin;
use yii\web\ForbiddenHttpException;

/**
 * Shared plumbing: the permission checks and the site the screen is looking at.
 */
abstract class BaseController extends Controller
{
    protected function plugin(): Plugin
    {
        return Plugin::getInstance();
    }

    protected function requireView(): void
    {
        $this->requirePermission(Plugin::PERMISSION_VIEW);
    }

    protected function requireManage(): void
    {
        $this->requirePermission(Plugin::PERMISSION_MANAGE);
    }

    /**
     * @throws ForbiddenHttpException when the edition does not include the feature.
     */
    protected function requirePro(string $feature): void
    {
        if (!$this->plugin()->isPro()) {
            throw new ForbiddenHttpException(Craft::t('publishr', '{feature} is a Publishr Pro feature.', ['feature' => $feature]));
        }
    }

    /**
     * The site this screen is about.
     *
     * Falls back to the primary site rather than to "whatever the request happened to be for",
     * because a control-panel request in a site the user cannot edit would otherwise silently show
     * them an empty calendar rather than telling them why.
     */
    protected function siteId(): int
    {
        $handle = $this->request->getParam('site');

        if ($handle !== null) {
            $site = Craft::$app->getSites()->getSiteByHandle((string)$handle);

            if ($site !== null) {
                return (int)$site->id;
            }
        }

        return (int)(Craft::$app->getSites()->getCurrentSite()->id ?? Craft::$app->getSites()->getPrimarySite()->id);
    }

    protected function currentUserId(): ?int
    {
        $id = Craft::$app->getUser()->getId();

        return $id !== null ? (int)$id : null;
    }

    /** @return array<int, array{label: string, value: string}> */
    protected function siteOptions(): array
    {
        $options = [];

        foreach (Craft::$app->getSites()->getEditableSites() as $site) {
            $options[] = ['label' => $site->name, 'value' => $site->handle];
        }

        return $options;
    }
}
