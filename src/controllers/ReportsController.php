<?php

declare(strict_types=1);

namespace justinholtweb\publishr\controllers;

use Craft;
use craft\db\Query;
use craft\elements\Entry;
use craft\elements\User;
use justinholtweb\publishr\models\Item;
use justinholtweb\publishr\Plugin;
use justinholtweb\publishr\records\Table;
use yii\web\Response;

class ReportsController extends BaseController
{
    public function actionIndex(): Response
    {
        $this->requirePermission(Plugin::PERMISSION_REPORTS);
        $this->requirePro(Craft::t('publishr', 'The governance report'));

        $siteId = $this->siteId();

        return $this->renderTemplate('publishr/report', [
            'title' => Craft::t('publishr', 'Report'),
            'report' => $this->plugin()->governance->report($siteId),
            'siteHandle' => Craft::$app->getSites()->getSiteById($siteId)?->handle,
            'siteOptions' => $this->siteOptions(),
        ]);
    }

    /**
     * The same report as CSV.
     *
     * One row per tracked piece rather than a rendering of the summary boxes, because the reason
     * somebody exports a governance report is to sort it their own way in a spreadsheet — handing
     * them the aggregates back would be handing them the one thing they can already see.
     */
    public function actionExport(): Response
    {
        $this->requirePermission(Plugin::PERMISSION_REPORTS);
        $this->requirePro(Craft::t('publishr', 'The governance report'));

        $plugin = $this->plugin();
        $siteId = $this->siteId();

        $out = fopen('php://temp', 'r+');
        fputcsv($out, ['Title', 'Section', 'Stage', 'Assignee', 'Due', 'Status', 'Review due', 'Staleness', 'URL']);

        foreach ($this->rows($siteId) as $row) {
            fputcsv($out, $row);
        }

        rewind($out);
        $csv = stream_get_contents($out);
        fclose($out);

        return $this->response->sendContentAsFile((string)$csv, 'publishr-report-' . date('Y-m-d') . '.csv', [
            'mimeType' => 'text/csv',
        ]);
    }

    /**
     * Every tracked piece in the site.
     *
     * Streamed in ID order out of the item table rather than assembled from the summary queries:
     * the summaries are capped for the screen, and a CSV that silently stopped at 25 rows would be
     * worse than no CSV.
     *
     * @return array<int, string[]>
     */
    private function rows(int $siteId): array
    {
        $plugin = $this->plugin();
        $rows = [];

        $ids = array_map('intval', (new Query())
            ->select(['elementId'])
            ->from([Table::ITEMS])
            ->where(['siteId' => $siteId])
            ->orderBy(['id' => SORT_ASC])
            ->column());

        // Hydrated a chunk at a time — items, entries and assignees in three queries per 500 rows,
        // not three per row. On a nine-thousand-piece site the difference is a download versus a
        // gateway timeout.
        foreach (array_chunk($ids, 500) as $chunk) {
            $items = $plugin->items->forElements($chunk, $siteId);

            $entries = Entry::find()
                ->id($chunk)
                ->siteId($siteId)
                ->status(null)
                ->drafts(null)
                ->revisions(false)
                ->limit(null)
                ->indexBy('id')
                ->all();

            $assigneeIds = array_values(array_unique(array_filter(array_map(
                static fn(Item $item) => $item->assigneeId,
                $items,
            ))));

            $assignees = $assigneeIds === [] ? [] : User::find()
                ->id($assigneeIds)
                ->status(null)
                ->limit(null)
                ->indexBy('id')
                ->all();

            foreach ($chunk as $elementId) {
                $item = $items[$elementId] ?? null;
                $entry = $entries[$elementId] ?? null;

                if ($item === null || $entry === null) {
                    continue;
                }

                $rows[] = array_map([$this, 'cell'], [
                    (string)$entry->title,
                    (string)($entry->getSection()->name ?? ''),
                    (string)($item->getStage()->name ?? ''),
                    (string)(($assignees[$item->assigneeId] ?? null)->friendlyName ?? ''),
                    (string)($item->dueDate?->format('Y-m-d') ?? ''),
                    (string)$entry->getStatus(),
                    (string)($item->reviewDue?->format('Y-m-d') ?? ''),
                    (string)$plugin->freshness->staleness($item),
                    (string)($entry->getUrl() ?? ''),
                ]);
            }
        }

        return $rows;
    }

    /**
     * A cell a spreadsheet will not execute. An entry titled `=HYPERLINK(...)` is text in Craft
     * and a formula in Excel; the leading apostrophe keeps it text.
     */
    private function cell(string $value): string
    {
        return $value !== '' && in_array($value[0], ['=', '+', '-', '@', "\t", "\r"], true)
            ? "'" . $value
            : $value;
    }
}
