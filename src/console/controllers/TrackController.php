<?php

declare(strict_types=1);

namespace justinholtweb\publishr\console\controllers;

use craft\console\Controller;
use craft\elements\Entry;
use craft\helpers\Console;
use justinholtweb\publishr\Plugin;
use yii\console\ExitCode;

/**
 * `php craft publishr/track`
 *
 * Backfills editorial records for content that predates the plugin. Publishr is almost always
 * installed on a site with several years of entries already on it, and a calendar that starts
 * empty is a calendar that stays empty.
 */
class TrackController extends Controller
{
    /** Limit to one section handle. */
    public ?string $section = null;

    /** Show what would happen and write nothing. */
    public bool $dryRun = false;

    /** Most entries to touch in one run. */
    public int $limit = 1000;

    public $defaultAction = 'backfill';

    public function options($actionID): array
    {
        return array_merge(parent::options($actionID), ['section', 'dryRun', 'limit']);
    }

    /**
     * Print the ID of one entry Publishr manages, or nothing.
     *
     * For the control-panel smoke test, which needs a real entry-editor URL: Craft's element index
     * is rendered by JavaScript, so there is no link in the HTML for a shell script to scrape.
     */
    public function actionFirstEntry(): int
    {
        $settings = Plugin::getInstance()->getSettings();

        foreach (Entry::find()->status(null)->drafts(false)->revisions(false)->limit(25)->all() as $entry) {
            if ($settings->managesSection($entry->getSection()?->uid)) {
                $this->stdout((string)$entry->id);

                return ExitCode::OK;
            }
        }

        return ExitCode::OK;
    }

    public function actionBackfill(): int
    {
        $plugin = Plugin::getInstance();
        $settings = $plugin->getSettings();

        $query = Entry::find()
            ->status(null)
            ->drafts(false)
            ->revisions(false)
            ->limit($this->limit);

        if ($this->section !== null) {
            $query->section($this->section);
        }

        $created = 0;
        $skipped = 0;

        foreach ($query->all() as $entry) {
            if (!$settings->managesSection($entry->getSection()?->uid)) {
                $skipped++;

                continue;
            }

            if ($plugin->items->forEntry($entry) !== null) {
                $skipped++;

                continue;
            }

            if ($this->dryRun) {
                $this->stdout('  would track  ' . $entry->title . "\n");
                $created++;

                continue;
            }

            // A piece that is already live starts on the published stage, not on "Idea". Dropping
            // three years of published articles onto the first column would produce a board that
            // says the entire archive is unwritten.
            $stage = $entry->getStatus() === Entry::STATUS_LIVE
                ? $plugin->stages->getPublishedStage()
                : $plugin->stages->getDefaultStage();

            $item = $plugin->items->create((int)$entry->getCanonicalId(), (int)$entry->siteId, $stage?->id);

            if ($settings->freshnessEnabled) {
                $item->setElement($entry);
                $plugin->freshness->applyPolicy($item, $entry, $entry->postDate);
                $plugin->items->save($item);
            }

            $created++;
        }

        $this->stdout("Tracked $created, skipped $skipped.\n", Console::FG_GREEN);

        return ExitCode::OK;
    }
}
