<?php

declare(strict_types=1);

namespace justinholtweb\publishr\services;

use Craft;
use craft\base\Component;
use craft\db\Query;
use craft\elements\Entry;
use craft\helpers\DateTimeHelper;
use craft\helpers\Db;
use DateInterval;
use DateTime;
use justinholtweb\publishr\models\Item;
use justinholtweb\publishr\Plugin;
use justinholtweb\publishr\records\Table;
use yii\db\Expression;

/**
 * The report somebody takes into a meeting.
 *
 * Every number here answers a question a person actually asks out loud, and the ones that were
 * tempting but got left out are as much of the design as the ones that stayed. "Average time to
 * publish" is not here, because it is dominated by the two pieces that sat in a drawer for a year
 * and tells you nothing about the desk. "Words published this month" is not here, because it
 * rewards the wrong thing and everybody knows it within a week.
 *
 * What is here: where work is piling up, who is carrying it, what is late, what is unowned, and
 * what has quietly gone stale.
 */
class Governance extends Component
{
    /**
     * Everything the report screen needs, in one call.
     *
     * @return array<string, mixed>
     */
    public function report(?int $siteId = null, int $windowDays = 30): array
    {
        return [
            'coverage' => $this->coverage($siteId),
            'stages' => $this->stageBreakdown($siteId),
            'bottlenecks' => $this->bottlenecks($siteId),
            'workload' => $this->workload($siteId),
            'late' => Plugin::getInstance()->items->overdue($siteId, 25),
            'unassigned' => Plugin::getInstance()->items->unassigned($siteId, 25),
            'stale' => $this->stalest($siteId, 25),
            'freshness' => Plugin::getInstance()->freshness->summary($siteId),
            'throughput' => $this->throughput($siteId, $windowDays),
        ];
    }

    /**
     * How much of the site is actually on the calendar.
     *
     * The first number a governance report has to be honest about. A workflow covering 6% of a
     * site's content is not a workflow, and every other figure on the page is measuring that 6%.
     *
     * @return array{entries: int, tracked: int, percent: int}
     */
    public function coverage(?int $siteId = null): array
    {
        $settings = Plugin::getInstance()->getSettings();

        $query = Entry::find()->status(null)->drafts(false)->revisions(false);

        if ($siteId !== null) {
            $query->siteId($siteId);
        }

        if ($settings->sections !== []) {
            $handles = [];

            foreach ($settings->sections as $uid) {
                $section = Craft::$app->getEntries()->getSectionByUid($uid);

                if ($section !== null) {
                    $handles[] = $section->handle;
                }
            }

            $query->section($handles !== [] ? $handles : ['__publishr_no_such_section__']);
        }

        $entries = (int)$query->count();

        $tracked = (new Query())->from([Table::ITEMS]);

        if ($siteId !== null) {
            $tracked->where(['siteId' => $siteId]);
        }

        $tracked = (int)$tracked->count();

        return [
            'entries' => $entries,
            'tracked' => $tracked,
            'percent' => $entries > 0 ? (int)round(min($tracked, $entries) / $entries * 100) : 0,
        ];
    }

    /** @return array<int, array{stage: \justinholtweb\publishr\models\Stage, count: int, overdue: int}> */
    public function stageBreakdown(?int $siteId = null): array
    {
        $plugin = Plugin::getInstance();
        $counts = $plugin->items->countsByStage($siteId);
        $now = Db::prepareDateForDb(DateTimeHelper::now());

        $overdueQuery = (new Query())
            ->select(['stageId', 'c' => 'COUNT(*)'])
            ->from([Table::ITEMS])
            ->where(['not', ['dueDate' => null]])
            ->andWhere(['<', 'dueDate', $now])
            ->groupBy(['stageId']);

        if ($siteId !== null) {
            $overdueQuery->andWhere(['siteId' => $siteId]);
        }

        $overdue = [];

        foreach ($overdueQuery->all() as $row) {
            $overdue[(int)$row['stageId']] = (int)$row['c'];
        }

        $out = [];

        foreach ($plugin->stages->getAllStages() as $stage) {
            $out[] = [
                'stage' => $stage,
                'count' => $counts[(int)$stage->id] ?? 0,
                'overdue' => $overdue[(int)$stage->id] ?? 0,
            ];
        }

        return $out;
    }

    /**
     * Where work is sitting longest.
     *
     * Measured as the median age of the items currently on each stage, taken from the history row
     * that put them there. The **median**, not the mean, and that is the whole point: one piece
     * abandoned on "Needs edit" for eight months drags a mean into meaninglessness, and every desk
     * has one of those. The median says what happens to a normal piece.
     *
     * @return array<int, array{stage: string, medianDays: int, count: int}>
     */
    public function bottlenecks(?int $siteId = null): array
    {
        $plugin = Plugin::getInstance();
        $now = DateTimeHelper::now();
        $out = [];

        foreach ($plugin->stages->getAllStages() as $stage) {
            $query = (new Query())
                ->select(['i.elementId', 'i.siteId', 'entered' => new Expression('MAX([[h]].[[dateCreated]])')])
                ->from(['i' => Table::ITEMS])
                ->leftJoin(['h' => Table::HISTORY], '[[h]].[[elementId]] = [[i]].[[elementId]] AND [[h]].[[siteId]] = [[i]].[[siteId]] AND [[h]].[[toStageId]] = [[i]].[[stageId]]')
                ->where(['i.stageId' => $stage->id])
                ->groupBy(['i.elementId', 'i.siteId']);

            if ($siteId !== null) {
                $query->andWhere(['i.siteId' => $siteId]);
            }

            $ages = [];

            foreach ($query->all() as $row) {
                if (empty($row['entered'])) {
                    continue;
                }

                $entered = DateTimeHelper::toDateTime($row['entered'], false, false);

                if ($entered === false) {
                    continue;
                }

                $ages[] = (int)floor(($now->getTimestamp() - $entered->getTimestamp()) / 86400);
            }

            $out[] = [
                'stage' => $stage->name,
                'color' => $stage->color,
                'count' => count($ages),
                'medianDays' => $this->median($ages),
            ];
        }

        return $out;
    }

    /**
     * Who is carrying what.
     *
     * @return array<int, array{userId: int|null, name: string, total: int, overdue: int}>
     */
    public function workload(?int $siteId = null): array
    {
        $now = Db::prepareDateForDb(DateTimeHelper::now());

        $query = (new Query())
            ->select([
                'assigneeId',
                'total' => 'COUNT(*)',
                'overdue' => new Expression('SUM(CASE WHEN [[dueDate]] IS NOT NULL AND [[dueDate]] < :now THEN 1 ELSE 0 END)'),
            ])
            ->from([Table::ITEMS])
            ->params([':now' => $now])
            ->groupBy(['assigneeId'])
            ->orderBy(['total' => SORT_DESC]);

        if ($siteId !== null) {
            $query->where(['siteId' => $siteId]);
        }

        $out = [];

        foreach ($query->all() as $row) {
            $userId = $row['assigneeId'] !== null ? (int)$row['assigneeId'] : null;
            $user = $userId !== null ? Craft::$app->getUsers()->getUserById($userId) : null;

            $out[] = [
                'userId' => $userId,
                'name' => $user?->friendlyName ?? Craft::t('publishr', 'Nobody'),
                'total' => (int)$row['total'],

                // SUM() over an empty group is NULL, and SUM() itself returns a *string* on both
                // MySQL and Postgres. Casting is not decoration.
                'overdue' => (int)($row['overdue'] ?? 0),
            ];
        }

        return $out;
    }

    /** @return Item[] The most overdue reviews, worst first. */
    public function stalest(?int $siteId = null, int $limit = 25): array
    {
        $items = Plugin::getInstance()->freshness->due($siteId, $limit * 2);

        usort($items, static fn(Item $a, Item $b) => ($a->reviewDue?->getTimestamp() ?? 0) <=> ($b->reviewDue?->getTimestamp() ?? 0));

        return array_slice($items, 0, $limit);
    }

    /**
     * Pieces that reached the published stage in the last N days, per week.
     *
     * Read off the history rather than off `postDate`, deliberately. `postDate` is editable and
     * routinely backdated — a piece imported from an old site, a piece re-dated to sit at the top
     * of a listing — so a chart built on it shows a burst of activity in 2019 that never happened.
     * The history row records when the desk actually finished the work.
     *
     * @return array<string, int> `Y-\WW` => count.
     */
    public function throughput(?int $siteId = null, int $days = 90): array
    {
        $published = Plugin::getInstance()->stages->getPublishedStage();

        if ($published === null) {
            return [];
        }

        $since = (new DateTime())->sub(new DateInterval("P{$days}D"));

        $query = (new Query())
            ->select(['dateCreated'])
            ->from([Table::HISTORY])
            ->where(['toStageId' => $published->id])
            ->andWhere(['>=', 'dateCreated', Db::prepareDateForDb($since)]);

        if ($siteId !== null) {
            $query->andWhere(['siteId' => $siteId]);
        }

        $buckets = [];

        // Seeded with every week in the window, so a fortnight with nothing in it draws as two
        // empty columns rather than closing up and making the desk look busier than it was.
        $cursor = (clone $since);

        for ($i = 0; $i <= (int)ceil($days / 7); $i++) {
            $buckets[$cursor->format('o-\WW')] = 0;
            $cursor = $cursor->add(new DateInterval('P7D'));
        }

        foreach ($query->all() as $row) {
            $date = DateTimeHelper::toDateTime($row['dateCreated'], false, false);

            if ($date === false) {
                continue;
            }

            $key = $date->format('o-\WW');
            $buckets[$key] = ($buckets[$key] ?? 0) + 1;
        }

        ksort($buckets);

        return $buckets;
    }

    /** @param int[] $values */
    private function median(array $values): int
    {
        if ($values === []) {
            return 0;
        }

        sort($values);
        $count = count($values);
        $middle = intdiv($count, 2);

        return $count % 2 === 1
            ? $values[$middle]
            : (int)round(($values[$middle - 1] + $values[$middle]) / 2);
    }
}
