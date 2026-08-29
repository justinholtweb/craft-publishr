<?php

declare(strict_types=1);

namespace justinholtweb\publishr\controllers;

use Craft;
use justinholtweb\publishr\models\Comment;
use justinholtweb\publishr\Plugin;
use yii\web\ForbiddenHttpException;
use yii\web\Response;

class CommentsController extends BaseController
{
    public function actionSave(): Response
    {
        $this->requirePostRequest();
        $this->requirePermission(Plugin::PERMISSION_COMMENT);

        $comment = new Comment([
            'elementId' => (int)$this->request->getRequiredBodyParam('elementId'),
            'siteId' => (int)($this->request->getBodyParam('siteId') ?: $this->siteId()),
            'authorId' => $this->currentUserId(),
            'parentId' => ($p = $this->request->getBodyParam('parentId')) ? (int)$p : null,
            'body' => trim((string)$this->request->getRequiredBodyParam('body')),
        ]);

        if ($comment->body === '') {
            return $this->asFailure(Craft::t('publishr', 'Say something.'));
        }

        // The item is created on first comment. Somebody starting a conversation about a piece is
        // as good a signal that it belongs on the calendar as anything the plugin could infer.
        $this->plugin()->items->forElement($comment->elementId, $comment->siteId)
            ?? $this->plugin()->items->create($comment->elementId, $comment->siteId);

        if (!$this->plugin()->comments->save($comment)) {
            return $this->asModelFailure($comment, Craft::t('publishr', 'Couldn’t save that comment.'), 'comment');
        }

        return $this->asSuccess(Craft::t('publishr', 'Posted.'), ['id' => $comment->id]);
    }

    public function actionResolve(): Response
    {
        $this->requirePostRequest();
        $this->requirePermission(Plugin::PERMISSION_COMMENT);

        $id = (int)$this->request->getRequiredBodyParam('id');
        $resolved = (bool)$this->request->getBodyParam('resolved', true);

        if (!$this->plugin()->comments->resolve($id, $this->currentUserId(), $resolved)) {
            return $this->asFailure(Craft::t('publishr', 'No such comment.'));
        }

        return $this->asSuccess($resolved
            ? Craft::t('publishr', 'Resolved.')
            : Craft::t('publishr', 'Reopened.'));
    }

    /**
     * Delete a comment.
     *
     * Its author, or somebody who can change the workflow. Not everybody who can comment — an
     * editorial trail that any colleague can quietly edit is not a trail.
     */
    public function actionDelete(): Response
    {
        $this->requirePostRequest();
        $this->requirePermission(Plugin::PERMISSION_COMMENT);

        $id = (int)$this->request->getRequiredBodyParam('id');
        $comment = $this->plugin()->comments->getById($id);

        if ($comment === null) {
            return $this->asFailure(Craft::t('publishr', 'No such comment.'));
        }

        $isAuthor = $comment->authorId !== null && $comment->authorId === $this->currentUserId();

        if (!$isAuthor && !Craft::$app->getUser()->checkPermission(Plugin::PERMISSION_SETTINGS)) {
            throw new ForbiddenHttpException(Craft::t('publishr', 'That isn’t your comment.'));
        }

        $this->plugin()->comments->delete($id);

        return $this->asSuccess(Craft::t('publishr', 'Deleted.'));
    }
}
