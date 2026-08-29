<?php

declare(strict_types=1);

namespace justinholtweb\publishr\controllers;

use Craft;
use craft\elements\Entry;
use justinholtweb\publishr\models\Item;
use justinholtweb\publishr\Plugin;
use yii\web\Response;

/**
 * Freshness reviews: what has gone stale, and what is about to.
 */
class ReviewsController extends BaseController
{
    public function actionIndex(): Response
    {
        $this->requireView();
        $this->requirePro(Craft::t('publishr', 'Freshness reviews'));

        $plugin = $this->plugin();
        $siteId = $this->siteId();
        $horizon = (int)$this->request->getParam('within', 30);

        $items = $plugin->freshness->due($siteId, 300, $horizon);
        $ids = array_map(static fn(Item $item) => $item->elementId, $items);

        $entries = $ids === [] ? [] : Entry::find()
            ->id($ids)
            ->siteId($siteId)
            ->status(null)
            ->drafts(false)
            ->revisions(false)
            ->limit(null)
            ->indexBy('id')
            ->all();

        $rows = [];

        foreach ($items as $item) {
            if (!isset($entries[$item->elementId])) {
                continue;
            }

            $rows[] = [
                'entry' => $entries[$item->elementId],
                'item' => $item,
                'staleness' => $plugin->freshness->staleness($item),
                'policy' => $plugin->freshness->policyFor($item, $entries[$item->elementId]),
            ];
        }

        // Worst first. A reviews screen sorted by date puts the mildly-overdue at the top, which
        // is exactly the wrong end of a backlog to start at.
        usort($rows, static fn(array $a, array $b) => $b['staleness'] <=> $a['staleness']);

        return $this->renderTemplate('publishr/reviews', [
            'title' => Craft::t('publishr', 'Reviews'),
            'rows' => $rows,
            'horizon' => $horizon,
            'summary' => $plugin->freshness->summary($siteId),
            'policies' => $plugin->policies->getAllPolicies(),
            'siteHandle' => Craft::$app->getSites()->getSiteById($siteId)?->handle,
            'siteOptions' => $this->siteOptions(),
            'canReview' => Craft::$app->getUser()->checkPermission(Plugin::PERMISSION_REVIEW),
        ]);
    }

    /** Schedule reviews for everything that has none yet. */
    public function actionSchedule(): Response
    {
        $this->requirePostRequest();
        $this->requirePermission(Plugin::PERMISSION_REVIEW);
        $this->requirePro(Craft::t('publishr', 'Freshness reviews'));

        $count = $this->plugin()->freshness->sweep();

        $this->setSuccessFlash(Craft::t('publishr', '{n, plural, =0{Nothing new to schedule} =1{One review scheduled} other{# reviews scheduled}}.', ['n' => $count]));

        return $this->redirectToPostedUrl();
    }
}
