<?php

declare(strict_types=1);

namespace justinholtweb\publishr\controllers;

use Craft;
use craft\db\Query;
use craft\elements\Entry;
use justinholtweb\publishr\Plugin;
use justinholtweb\publishr\records\Table;
use yii\db\Expression;
use yii\web\Response;

/**
 * The board: one column per stage.
 *
 * The calendar answers "when", the board answers "how far along". Both read the same rows; neither
 * is a mode of the other, because the questions get asked by different people — a board is what an
 * editor stands in front of on a Monday, and a calendar is what a marketing manager checks before
 * promising a client a date.
 */
class BoardController extends BaseController
{
    public function actionIndex(): Response
    {
        $this->requireView();

        $plugin = $this->plugin();
        $siteId = $this->siteId();
        $stages = $plugin->stages->getAllStages();

        $assigneeId = $this->request->getParam('assigneeId');

        if ($assigneeId === 'me') {
            $assigneeId = $this->currentUserId();
        }

        $columns = [];

        foreach ($stages as $stage) {
            $query = (new Query())
                ->select(['elementId'])
                ->from([Table::ITEMS])
                ->where(['siteId' => $siteId, 'stageId' => $stage->id])
                ->orderBy([
                    'pinnedFirst' => new Expression('CASE WHEN [[pinned]] = 1 THEN 0 ELSE 1 END'),
                    'nullsLast' => new Expression('CASE WHEN [[dueDate]] IS NULL THEN 1 ELSE 0 END'),
                    'dueDate' => SORT_ASC,
                ])
                // Capped per column, because a "Published" column on a site with 9,000 articles is
                // not a column anybody scrolls — it is a page that never finishes rendering.
                ->limit(60);

            if (!empty($assigneeId)) {
                $query->andWhere(['assigneeId' => (int)$assigneeId]);
            }

            $ids = array_map('intval', $query->column());

            $total = (new Query())
                ->from([Table::ITEMS])
                ->where(['siteId' => $siteId, 'stageId' => $stage->id]);

            if (!empty($assigneeId)) {
                $total->andWhere(['assigneeId' => (int)$assigneeId]);
            }

            $columns[] = [
                'stage' => $stage,
                'cards' => $this->cards($ids, $siteId),
                'total' => (int)$total->count(),
            ];
        }

        return $this->renderTemplate('publishr/board', [
            'title' => Craft::t('publishr', 'Board'),
            'columns' => $columns,
            'assigneeId' => $assigneeId,
            'siteHandle' => Craft::$app->getSites()->getSiteById($siteId)?->handle,
            'siteOptions' => $this->siteOptions(),
            'canManage' => Craft::$app->getUser()->checkPermission(Plugin::PERMISSION_MANAGE),
        ]);
    }

    /** @param int[] $ids */
    private function cards(array $ids, int $siteId): array
    {
        if ($ids === []) {
            return [];
        }

        $plugin = $this->plugin();

        $entries = Entry::find()
            ->id($ids)
            ->siteId($siteId)
            ->status(null)
            ->drafts(null)
            ->revisions(false)
            ->limit(null)
            ->indexBy('id')
            ->all();

        $items = $plugin->items->forElements($ids, $siteId);
        $comments = $plugin->comments->openCounts($ids, $siteId);

        $cards = [];

        foreach ($ids as $id) {
            if (isset($entries[$id])) {
                $cards[] = [
                    'entry' => $entries[$id],
                    'item' => $items[$id] ?? null,
                    'openComments' => $comments[$id] ?? 0,
                ];
            }
        }

        return $cards;
    }
}
