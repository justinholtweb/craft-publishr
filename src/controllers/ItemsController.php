<?php

declare(strict_types=1);

namespace justinholtweb\publishr\controllers;

use Craft;
use craft\elements\Entry;
use craft\helpers\DateTimeHelper;
use justinholtweb\publishr\models\Item;
use justinholtweb\publishr\Plugin;
use yii\web\BadRequestHttpException;
use yii\web\Response;

/**
 * Everything that changes an editorial record.
 */
class ItemsController extends BaseController
{
    /** Move a piece to a stage. */
    public function actionStage(): Response
    {
        $this->requirePostRequest();
        $this->requireManage();

        $item = $this->itemFromRequest(true);
        $stageId = $this->request->getBodyParam('stageId');
        $stageId = $stageId === '' || $stageId === null ? null : (int)$stageId;

        $force = (bool)$this->request->getBodyParam('force', false);

        if ($force) {
            // The override is a *permission*, checked here rather than trusted from the form. A
            // hidden input is not an authorisation.
            $this->requirePermission(Plugin::PERMISSION_OVERRIDE_GATES);
        }

        $problems = [];

        if (!$this->plugin()->items->moveToStage($item, $stageId, $this->currentUserId(), $this->request->getBodyParam('note'), $force, $problems)) {
            $message = $problems !== []
                ? Craft::t('publishr', 'Not yet: {problems}', ['problems' => implode('; ', $problems)])
                : Craft::t('publishr', 'Couldn’t move that.');

            return $this->asFailure($message, ['problems' => $problems]);
        }

        return $this->asSuccess(Craft::t('publishr', 'Moved.'), $this->itemData($item));
    }

    public function actionAssign(): Response
    {
        $this->requirePostRequest();
        $this->requirePermission(Plugin::PERMISSION_ASSIGN);

        $item = $this->itemFromRequest(true);
        $assigneeId = $this->request->getBodyParam('assigneeId');

        // A relation field posts `[ "12" ]`; a select posts `"12"`; clearing either posts `""`.
        if (is_array($assigneeId)) {
            $assigneeId = $assigneeId[0] ?? null;
        }

        $assigneeId = $assigneeId === '' || $assigneeId === null ? null : (int)$assigneeId;

        if (!$this->plugin()->items->assign($item, $assigneeId, $this->currentUserId())) {
            return $this->asFailure(Craft::t('publishr', 'Couldn’t assign that.'));
        }

        return $this->asSuccess(Craft::t('publishr', 'Assigned.'), $this->itemData($item));
    }

    public function actionDue(): Response
    {
        $this->requirePostRequest();
        $this->requireManage();

        $item = $this->itemFromRequest(true);
        $raw = $this->request->getBodyParam('dueDate');

        $due = null;

        if (!empty($raw)) {
            // `toDateTime` with `$assumeSystemTimeZone = true`, because this value came out of a
            // date field a person filled in, in their own time zone — not out of a UTC column.
            $due = DateTimeHelper::toDateTime($raw, true);

            if ($due === false) {
                return $this->asFailure(Craft::t('publishr', 'That isn’t a date.'));
            }
        }

        if (!$this->plugin()->items->setDueDate($item, $due, $this->currentUserId())) {
            return $this->asFailure(Craft::t('publishr', 'Couldn’t set that deadline.'));
        }

        return $this->asSuccess(Craft::t('publishr', 'Saved.'), $this->itemData($item));
    }

    /** The brief — what the piece is meant to be, in the commissioner's words. */
    public function actionBrief(): Response
    {
        $this->requirePostRequest();
        $this->requireManage();

        $item = $this->itemFromRequest(true);
        $item->brief = (string)$this->request->getBodyParam('brief', '') ?: null;

        if (!$this->plugin()->items->save($item)) {
            return $this->asFailure(Craft::t('publishr', 'Couldn’t save the brief.'));
        }

        return $this->asSuccess(Craft::t('publishr', 'Saved.'));
    }

    /** Start tracking something that is not on the calendar yet. */
    public function actionTrack(): Response
    {
        $this->requirePostRequest();
        $this->requireManage();

        $entry = $this->entryFromRequest();
        $item = $this->plugin()->items->forEntry($entry, true);

        return $item !== null
            ? $this->asSuccess(Craft::t('publishr', 'Tracked.'), $this->itemData($item))
            : $this->asFailure(Craft::t('publishr', 'Couldn’t track that.'));
    }

    public function actionUntrack(): Response
    {
        $this->requirePostRequest();
        $this->requireManage();

        $entry = $this->entryFromRequest();

        $this->plugin()->items->deleteForElement((int)$entry->getCanonicalId(), (int)$entry->siteId);

        return $this->asSuccess(Craft::t('publishr', 'No longer tracked.'));
    }

    /** Re-run the checklist and hand back the verdict. */
    public function actionCheck(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();
        $this->requireView();
        $this->requirePro(Craft::t('publishr', 'Publish requirements'));

        $entry = $this->entryFromRequest();
        $report = $this->plugin()->gates->report($entry, true);

        return $this->asJson([
            'success' => true,
            'report' => $report->toArray(),
        ]);
    }

    /** Tick manual checklist boxes. */
    public function actionTick(): Response
    {
        $this->requirePostRequest();
        $this->requireManage();
        $this->requirePro(Craft::t('publishr', 'Publish requirements'));

        $entry = $this->entryFromRequest();
        $gateHandle = (string)$this->request->getRequiredBodyParam('gate');
        $ticked = (array)$this->request->getBodyParam('ticked', []);

        if (!$this->plugin()->gates->setTicks($entry, $gateHandle, array_map('strval', $ticked))) {
            return $this->asFailure(Craft::t('publishr', 'Couldn’t save that.'));
        }

        return $this->asSuccess(Craft::t('publishr', 'Saved.'), [
            'report' => $this->plugin()->gates->report($entry)->toArray(),
        ]);
    }

    /** Mark a freshness review as done. */
    public function actionReviewed(): Response
    {
        $this->requirePostRequest();
        $this->requirePermission(Plugin::PERMISSION_REVIEW);
        $this->requirePro(Craft::t('publishr', 'Freshness reviews'));

        $item = $this->itemFromRequest(true);

        if (!$this->plugin()->items->markReviewed($item, $this->currentUserId(), $this->request->getBodyParam('note'))) {
            return $this->asFailure(Craft::t('publishr', 'Couldn’t record that review.'));
        }

        return $this->asSuccess(Craft::t('publishr', 'Reviewed. Next one due {date}.', [
            'date' => $item->reviewDue?->format('j M Y') ?? Craft::t('publishr', 'never'),
        ]), $this->itemData($item));
    }

    /** Follow or unfollow a piece. */
    public function actionWatch(): Response
    {
        $this->requirePostRequest();
        $this->requireView();
        $this->requirePro(Craft::t('publishr', 'Subscriptions'));

        $entry = $this->entryFromRequest();
        $userId = $this->currentUserId();

        if ($userId === null) {
            throw new BadRequestHttpException('Not logged in.');
        }

        $watching = $this->plugin()->notifications->toggleWatch($userId, (int)$entry->getCanonicalId());

        return $this->asSuccess(
            $watching ? Craft::t('publishr', 'Following.') : Craft::t('publishr', 'No longer following.'),
            ['watching' => $watching],
        );
    }

    /**
     * Bulk actions from the overview screen.
     *
     * Refuses partially rather than silently: a bulk move that could only apply to nine of eleven
     * selected pieces reports the two it skipped and why, because a bulk action that quietly does
     * less than it was asked to is how work goes missing.
     */
    public function actionBulk(): Response
    {
        $this->requirePostRequest();
        $this->requireManage();

        $ids = array_map('intval', (array)$this->request->getRequiredBodyParam('elementIds'));
        $siteId = (int)($this->request->getBodyParam('siteId') ?: $this->siteId());
        $action = (string)$this->request->getRequiredBodyParam('bulkAction');

        $done = 0;
        $skipped = [];

        foreach ($ids as $elementId) {
            $item = $this->plugin()->items->forElement($elementId, $siteId);

            if ($item === null) {
                $skipped[] = $elementId;

                continue;
            }

            $ok = match ($action) {
                'stage' => $this->plugin()->items->moveToStage(
                    $item,
                    (int)$this->request->getBodyParam('stageId') ?: null,
                    $this->currentUserId(),
                ),
                'assign' => $this->plugin()->items->assign(
                    $item,
                    ($v = $this->request->getBodyParam('assigneeId')) === '' || $v === null ? null : (int)$v,
                    $this->currentUserId(),
                ),
                'due' => $this->plugin()->items->setDueDate(
                    $item,
                    ($d = $this->request->getBodyParam('dueDate')) ? (DateTimeHelper::toDateTime($d, true) ?: null) : null,
                    $this->currentUserId(),
                ),
                default => false,
            };

            if ($ok) {
                $done++;
            } else {
                $skipped[] = $elementId;
            }
        }

        return $this->asSuccess(
            Craft::t('publishr', '{done} updated, {skipped} skipped.', ['done' => $done, 'skipped' => count($skipped)]),
            ['done' => $done, 'skipped' => $skipped],
        );
    }

    // ------------------------------------------------------------------ plumbing

    private function entryFromRequest(): Entry
    {
        $elementId = (int)$this->request->getRequiredBodyParam('elementId');
        $siteId = (int)($this->request->getBodyParam('siteId') ?: $this->siteId());

        $entry = Entry::find()
            ->id($elementId)
            ->siteId($siteId)
            ->status(null)
            ->drafts(null)
            ->revisions(false)
            ->one();

        if (!$entry instanceof Entry) {
            throw new BadRequestHttpException('No such entry.');
        }

        return $entry;
    }

    private function itemFromRequest(bool $create = false): Item
    {
        $entry = $this->entryFromRequest();
        $item = $this->plugin()->items->forEntry($entry, $create);

        if ($item === null) {
            throw new BadRequestHttpException('That isn’t being tracked.');
        }

        return $item;
    }

    /** @return array<string, mixed> */
    private function itemData(Item $item): array
    {
        return [
            'item' => [
                'elementId' => $item->elementId,
                'siteId' => $item->siteId,
                'stageId' => $item->stageId,
                'stage' => $item->getStage()?->name,
                'color' => $item->getStage()?->color,
                'assigneeId' => $item->assigneeId,
                'assignee' => $item->getAssignee()?->friendlyName,
                'dueDate' => $item->dueDate?->format('Y-m-d'),
                'reviewDue' => $item->reviewDue?->format('Y-m-d'),
                'overdue' => $item->isOverdue(),
            ],
        ];
    }
}
