<?php

declare(strict_types=1);

namespace justinholtweb\publishr\queue\jobs;

use Craft;
use craft\queue\BaseJob;
use justinholtweb\publishr\Plugin;

/**
 * Send whatever is pending.
 *
 * Takes no arguments on purpose. A job that carried a list of notification IDs would be stale by
 * the time it ran — rows raised a second later would wait for the next one — and two jobs holding
 * overlapping lists would both try to send the same message. Draining the queue table instead
 * makes the job idempotent and self-collapsing.
 */
class SendNotifications extends BaseJob
{
    public int $limit = 200;

    public function execute($queue): void
    {
        $result = Plugin::getInstance()->notifications->sendPending($this->limit);

        $this->setProgress($queue, 1, Craft::t('publishr', '{n} sent', ['n' => $result['sent']]));
    }

    protected function defaultDescription(): ?string
    {
        return Craft::t('publishr', 'Sending Publishr notifications');
    }
}
