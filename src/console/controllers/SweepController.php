<?php

declare(strict_types=1);

namespace justinholtweb\publishr\console\controllers;

use craft\console\Controller;
use craft\helpers\Console;
use justinholtweb\publishr\Plugin;
use yii\console\ExitCode;

/**
 * `php craft publishr/sweep`
 *
 * The one command a site needs on cron. Everything it does is idempotent, so running it every ten
 * minutes and running it once a day are both correct — the difference is only how promptly a
 * reminder arrives.
 */
class SweepController extends Controller
{
    /** Also send the daily digest. Give this to the once-a-morning cron entry, not the frequent one. */
    public bool $digest = false;

    public $defaultAction = 'run';

    public function options($actionID): array
    {
        return array_merge(parent::options($actionID), match ($actionID) {
            'run' => ['digest'],
            default => [],
        });
    }

    /** Advance published pieces, schedule reviews, raise reminders and send what is pending. */
    public function actionRun(): int
    {
        $result = Plugin::getInstance()->sweep->run($this->digest);

        $this->stdout("Publishr sweep\n", Console::FG_GREEN);

        foreach ($result as $label => $value) {
            $this->stdout(sprintf("  %-18s %d\n", $label, $value));
        }

        return ExitCode::OK;
    }

    /** Send the digest on its own, whatever the schedule says. */
    public function actionDigest(): int
    {
        $sent = Plugin::getInstance()->sweep->sendDigest();

        $this->stdout("Digest sent to $sent people.\n", Console::FG_GREEN);

        return ExitCode::OK;
    }

    /** Show what the sweep would be working with, without changing anything. */
    public function actionStatus(): int
    {
        $plugin = Plugin::getInstance();
        $freshness = $plugin->freshness->summary();

        $this->stdout("Publishr\n", Console::FG_GREEN);
        $this->stdout('  edition            ' . ($plugin->isPro() ? "Pro\n" : "Lite\n"));
        $this->stdout('  stages             ' . count($plugin->stages->getAllStages()) . "\n");
        $this->stdout('  tracked items      ' . $freshness['tracked'] . "\n");
        $this->stdout('  overdue            ' . count($plugin->items->overdue(null, 1000)) . "\n");
        $this->stdout('  unassigned         ' . count($plugin->items->unassigned(null, 1000)) . "\n");
        $this->stdout('  reviews scheduled  ' . $freshness['scheduled'] . "\n");
        $this->stdout('  reviews due        ' . $freshness['due'] . "\n");
        $this->stdout('  alarm clock        ' . (\justinholtweb\publishr\integrations\AlarmClock::isAvailable() ? "yes\n" : "no\n"));
        $this->stdout('  redpen             ' . (\justinholtweb\publishr\integrations\RedPen::isAvailable() ? "yes\n" : "no\n"));

        return ExitCode::OK;
    }
}
