<?php

declare(strict_types=1);

namespace justinholtweb\publishr\queue\jobs;

use Craft;
use craft\queue\BaseJob;
use justinholtweb\publishr\Plugin;

/**
 * The scheduled sweep, run off the queue.
 *
 * For sites with a queue runner but no cron — which is most shared hosting, and exactly the sites
 * where a missed deadline is least likely to be noticed.
 */
class RunSweep extends BaseJob
{
    public bool $digest = false;

    public function execute($queue): void
    {
        $result = Plugin::getInstance()->sweep->run($this->digest);

        $this->setProgress($queue, 1, Craft::t('publishr', '{n} notifications sent', ['n' => $result['sent']]));
    }

    protected function defaultDescription(): ?string
    {
        return Craft::t('publishr', 'Publishr editorial sweep');
    }
}
