<?php

declare(strict_types=1);

namespace justinholtweb\publishr\controllers;

use Craft;
use craft\db\Query;
use craft\elements\Entry;
use craft\helpers\DateTimeHelper;
use craft\helpers\Db;
use justinholtweb\publishr\models\Item;
use justinholtweb\publishr\Plugin;
use justinholtweb\publishr\records\Table;
use yii\db\Expression;
use yii\web\Response;

/**
 * The list views: everything in flight, and everything on your own desk.
 */
class OverviewController extends BaseController
{
    public function actionIndex(): Response
    {
        $this->requireView();

        $plugin = $this->plugin();
        $siteId = $this->siteId();

        $stageId = $this->request->getParam('stageId');
        $assigneeId = $this->request->getParam('assigneeId');
        $state = (string)$this->request->getParam('state', '');

        $query = (new Query())
            ->select(['elementId', 'siteId', 'stageId', 'assigneeId', 'dueDate', 'reviewDue'])
            ->from([Table::ITEMS])
            ->where(['siteId' => $siteId])
            ->orderBy([
                'nullsLast' => new Expression('CASE WHEN [[dueDate]] IS NULL THEN 1 ELSE 0 END'),
                'dueDate' => SORT_ASC,
            ])
            ->limit(500);

        if ($stageId !== null && $stageId !== '') {
            $query->andWhere(['stageId' => (int)$stageId]);
        }

        if ($assigneeId === 'me') {
            $assigneeId = $this->currentUserId();
        }

        if ($assigneeId === 'nobody') {
            $query->andWhere(['assigneeId' => null]);
        } elseif (!empty($assigneeId)) {
            $query->andWhere(['assigneeId' => (int)$assigneeId]);
        }

        $now = Db::prepareDateForDb(DateTimeHelper::now());

        if ($state === 'overdue') {
            $query->andWhere(['not', ['dueDate' => null]])->andWhere(['<', 'dueDate', $now]);
        } elseif ($state === 'reviewDue') {
            $query->andWhere(['not', ['reviewDue' => null]])->andWhere(['<=', 'reviewDue', $now]);
        }

        $rows = $query->all();
        $ids = array_map(static fn(array $row) => (int)$row['elementId'], $rows);

        return $this->renderTemplate('publishr/overview', [
            'title' => Craft::t('publishr', 'Overview'),
            'rows' => $this->hydrate($ids, $siteId),
            'stages' => $plugin->stages->getAllStages(),
            'stageId' => $stageId,
            'assigneeId' => $assigneeId,
            'state' => $state,
            'people' => $this->people(),
            'siteHandle' => Craft::$app->getSites()->getSiteById($siteId)?->handle,
            'siteOptions' => $this->siteOptions(),
            'canManage' => Craft::$app->getUser()->checkPermission(Plugin::PERMISSION_MANAGE),
            'isPro' => $plugin->isPro(),
        ]);
    }

    /** One person's queue. The screen most people open first, so it is its own URL. */
    public function actionMine(): Response
    {
        $this->requireView();

        $userId = $this->currentUserId();
        $plugin = $this->plugin();
        $siteId = $this->siteId();

        $items = $userId !== null ? $plugin->items->forAssignee($userId, $siteId, 200) : [];
        $ids = array_map(static fn(Item $item) => $item->elementId, $items);

        return $this->renderTemplate('publishr/mine', [
            'title' => Craft::t('publishr', 'My desk'),
            'rows' => $this->hydrate($ids, $siteId),
            'digest' => $userId !== null ? $plugin->sweep->digestFor($userId, 7) : ['overdue' => [], 'dueSoon' => [], 'reviews' => [], 'total' => 0],
            'stages' => $plugin->stages->getAllStages(),
            'siteHandle' => Craft::$app->getSites()->getSiteById($siteId)?->handle,
            'siteOptions' => $this->siteOptions(),
            'canManage' => Craft::$app->getUser()->checkPermission(Plugin::PERMISSION_MANAGE),
            'isPro' => $plugin->isPro(),
        ]);
    }

    public function actionActivity(): Response
    {
        $this->requireView();

        return $this->renderTemplate('publishr/activity', [
            'title' => Craft::t('publishr', 'Activity'),
            'entries' => $this->plugin()->items->recentActivity(100, $this->siteId()),
            'siteHandle' => Craft::$app->getSites()->getSiteById($this->siteId())?->handle,
            'siteOptions' => $this->siteOptions(),
        ]);
    }

    /**
     * Turn element IDs into rows the template can draw.
     *
     * One entry query and one item query for the whole page, rather than a lookup per row. On the
     * overview that is the difference between two queries and a thousand.
     *
     * @param int[] $ids
     * @return array<int, array{entry: Entry, item: Item|null, openComments: int}>
     */
    private function hydrate(array $ids, int $siteId): array
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

        // Never more than Craft would show this person: a section they cannot view, or a
        // colleague's draft they are not allowed to see, is left off the list.
        $elements = Craft::$app->getElements();
        $entries = array_filter($entries, static fn(Entry $entry) => $elements->canView($entry));

        $items = $plugin->items->forElements($ids, $siteId);
        $comments = $plugin->comments->openCounts($ids, $siteId);

        $rows = [];

        // Ordered by `$ids`, not by whatever the entry query returned — the sort is the whole
        // point of the list, and `indexBy` does not preserve it.
        foreach ($ids as $id) {
            if (!isset($entries[$id])) {
                continue;
            }

            $rows[] = [
                'entry' => $entries[$id],
                'item' => $items[$id] ?? null,
                'openComments' => $comments[$id] ?? 0,
            ];
        }

        return $rows;
    }

    /** @return array<int, array{label: string, value: string}> */
    private function people(): array
    {
        $ids = (new Query())
            ->select(['assigneeId'])
            ->distinct()
            ->from([Table::ITEMS])
            ->where(['not', ['assigneeId' => null]])
            ->column();

        $options = [];

        foreach ($ids as $id) {
            $user = Craft::$app->getUsers()->getUserById((int)$id);

            if ($user !== null) {
                $options[] = ['label' => $user->friendlyName, 'value' => (string)$user->id];
            }
        }

        usort($options, static fn(array $a, array $b) => $a['label'] <=> $b['label']);

        return $options;
    }
}
