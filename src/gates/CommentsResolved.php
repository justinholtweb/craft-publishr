<?php

declare(strict_types=1);

namespace justinholtweb\publishr\gates;

use Craft;
use craft\elements\Entry;
use justinholtweb\publishr\models\Gate;
use justinholtweb\publishr\models\GateResult;
use justinholtweb\publishr\Plugin;

/**
 * "Nobody is still asking a question about this."
 *
 * An open editorial comment is a colleague who has not had an answer. Publishing over the top of
 * one is how a factual correction gets lost, and it is the failure the plugin's comment thread
 * exists to prevent — so it is worth a gate rather than a hope.
 */
class CommentsResolved extends BaseGateType
{
    public static function handle(): string
    {
        return 'commentsResolved';
    }

    public static function displayName(): string
    {
        return Craft::t('publishr', 'No open comments');
    }

    public static function description(): string
    {
        return Craft::t('publishr', 'Every editorial comment on the piece has been marked resolved.');
    }

    public function check(Entry $entry, Gate $gate): GateResult
    {
        $open = Plugin::getInstance()->comments->openCount(
            (int)$entry->getCanonicalId(),
            (int)$entry->siteId,
        );

        if ($open > 0) {
            return $this->fail($gate, $this->t('{n, plural, =1{One comment is still open} other{# comments are still open}}', ['n' => $open]));
        }

        return $this->pass($gate);
    }
}
