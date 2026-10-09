<?php

declare(strict_types=1);

namespace justinholtweb\publishr\services;

use Craft;
use craft\base\Component;
use craft\db\Query;
use craft\elements\Entry;
use craft\elements\User;
use craft\helpers\Cp;
use craft\helpers\DateTimeHelper;
use craft\helpers\Db;
use craft\helpers\Html;
use DateTime;
use justinholtweb\publishr\models\Item;
use justinholtweb\publishr\models\Stage;
use justinholtweb\publishr\Plugin;
use justinholtweb\publishr\records\Table;

/**
 * Publishr inside Craft's own entries index: the stage, assignee and due-date columns (and card
 * attributes), and the SQL the condition rules filter with.
 *
 * Everything an index shows is drawn from the page, never per row. Each entry an element query
 * returns carries the whole result in `elementQueryResult`, so the first cell loads the editorial
 * records — and their assignees — for every entry on the page and the rest read the memo. A
 * per-row lookup would be three queries a row on a 100-row index.
 *
 * Every lookup keys on the **canonical** ID, like everything else in Publishr: the index shows
 * drafts, and a draft's own ID has no editorial record.
 */
class EntryIndex extends Component
{
    public const ATTR_STAGE = 'publishrStage';
    public const ATTR_ASSIGNEE = 'publishrAssignee';
    public const ATTR_DUE = 'publishrDueDate';

    /** The alias the condition subqueries give `publishr_items`. */
    public const ALIAS = 'publishr_index_items';

    /** @var array<string, Item|null> "siteId:canonicalId" => item, or null for "no record". */
    private array $_items = [];

    /** @var array<int, User|null> */
    private array $_users = [];

    /** @return array<string, array{label: string}> */
    public function tableAttributes(): array
    {
        return [
            self::ATTR_STAGE => ['label' => Craft::t('publishr', 'Editorial stage')],
            self::ATTR_ASSIGNEE => ['label' => Craft::t('publishr', 'Assignee')],
            self::ATTR_DUE => ['label' => Craft::t('publishr', 'Due')],
        ];
    }

    /**
     * Card attributes, with the placeholders Craft draws in the card designer.
     *
     * @return array<string, array{label: string, placeholder: callable}>
     */
    public function cardAttributes(): array
    {
        $attributes = $this->tableAttributes();

        $attributes[self::ATTR_STAGE]['placeholder'] = fn() => $this->stageHtml(
            Plugin::getInstance()->stages->getAllStages()[0] ?? null
        );
        $attributes[self::ATTR_ASSIGNEE]['placeholder'] = function() {
            $user = Craft::$app->getUser()->getIdentity();

            return $user !== null ? Cp::elementChipHtml($user) : '';
        };
        $attributes[self::ATTR_DUE]['placeholder'] = fn() => $this->dueHtml(
            DateTimeHelper::now()->modify('+3 days'),
            false
        );

        return $attributes;
    }

    public function isAttribute(string $attribute): bool
    {
        return isset($this->tableAttributes()[$attribute]);
    }

    /**
     * One cell. Empty for anybody who cannot see Publishr — the columns are the same data the
     * calendar shows, and they get the same permission.
     */
    public function attributeHtml(Entry $entry, string $attribute): string
    {
        if ($entry->id === null || !Craft::$app->getUser()->checkPermission(Plugin::PERMISSION_VIEW)) {
            return '';
        }

        $item = $this->itemFor($entry);

        if ($item === null) {
            return Html::tag('span', '–', ['class' => 'light', 'aria-label' => Craft::t('publishr', 'Not tracked')]);
        }

        return match ($attribute) {
            self::ATTR_STAGE => $this->stageHtml($item->getStage()),
            self::ATTR_ASSIGNEE => $this->assigneeHtml($item->assigneeId),
            self::ATTR_DUE => $this->dueHtml($item->dueDate, $this->isLate($item)),
            default => '',
        };
    }

    /**
     * The editorial record for an entry, loading the whole page's records the first time it is
     * asked about any of them.
     */
    public function itemFor(Entry $entry): ?Item
    {
        $siteId = (int)$entry->siteId;
        $key = $siteId . ':' . (int)$entry->getCanonicalId();

        if (!array_key_exists($key, $this->_items)) {
            $page = array_filter(
                $entry->elementQueryResult ?? [$entry],
                static fn($other) => $other instanceof Entry && $other->id !== null && (int)$other->siteId === $siteId,
            );
            $page[] = $entry;

            $ids = array_values(array_unique(array_map(static fn(Entry $e) => (int)$e->getCanonicalId(), $page)));
            $items = Plugin::getInstance()->items->forElements($ids, $siteId);

            foreach ($ids as $id) {
                $this->_items[$siteId . ':' . $id] = $items[$id] ?? null;
            }

            $this->primeUsers(array_filter(array_map(static fn(Item $i) => $i->assigneeId, $items)));
        }

        return $this->_items[$key];
    }

    /** Forget the memo — for a long-running process, and for tests that change items under it. */
    public function reset(): void
    {
        $this->_items = [];
        $this->_users = [];
    }

    /** A piece is late when its due day has passed and it has not already gone out. */
    public function isLate(Item $item): bool
    {
        if (!$item->isOverdue()) {
            return false;
        }

        $published = Plugin::getInstance()->stages->getPublishedStage();

        return $published === null || $item->stageId !== $published->id;
    }

    // ----------------------------------------------------------------- conditions

    /**
     * "There is an editorial record for this row" — correlated to the element query's subquery,
     * on the canonical ID and the row's own site, so a draft row matches its piece.
     */
    public function itemSubquery(): Query
    {
        $a = self::ALIAS;

        return (new Query())
            ->from([$a => Table::ITEMS])
            ->where("[[$a.elementId]] = COALESCE([[elements.canonicalId]], [[elements.id]])")
            ->andWhere("[[$a.siteId]] = [[elements_sites.siteId]]");
    }

    /**
     * The late-work condition, in the same terms as `Items::overdueCountFor()`: the due *day* has
     * passed in the site's time zone, and the piece is not on the published stage.
     *
     * @return array<int|string, mixed>
     */
    public function overdueCondition(): array
    {
        $a = self::ALIAS;
        $condition = ['and', ['<', "$a.dueDate", Db::prepareDateForDb(self::startOfToday())]];

        $published = Plugin::getInstance()->stages->getPublishedStage();

        if ($published !== null) {
            $condition[] = ['or', ["$a.stageId" => null], ['not', ["$a.stageId" => $published->id]]];
        }

        return $condition;
    }

    /** Midnight today in the site's zone — the first instant that is no longer late. */
    public static function startOfToday(): DateTime
    {
        return DateTimeHelper::now()->setTime(0, 0);
    }

    /** The editorial record for an entry, outside an index — what the rules' matchElement() use. */
    public function itemForElement(Entry $entry): ?Item
    {
        if ($entry->id === null) {
            return null;
        }

        return Plugin::getInstance()->items->forElement((int)$entry->getCanonicalId(), (int)$entry->siteId);
    }

    // ------------------------------------------------------------------- markup

    private function stageHtml(?Stage $stage): string
    {
        if ($stage === null) {
            return Html::tag('span', Craft::t('publishr', 'No stage'), ['class' => 'light']);
        }

        return Html::tag('span', Html::tag('span', '', [
            'class' => ['status', $stage->color],
            'aria-hidden' => 'true',
        ]) . Html::encode(Craft::t('site', $stage->name)), ['class' => 'publishr-stage']);
    }

    private function assigneeHtml(?int $userId): string
    {
        $user = $userId !== null ? $this->user($userId) : null;

        if ($user === null) {
            return Html::tag('span', Craft::t('publishr', 'Unassigned'), ['class' => 'light']);
        }

        return Cp::elementChipHtml($user);
    }

    private function dueHtml(?DateTime $due, bool $late): string
    {
        if ($due === null) {
            return Html::tag('span', '–', ['class' => 'light', 'aria-label' => Craft::t('publishr', 'No deadline')]);
        }

        $date = Html::encode(Craft::$app->getFormatter()->asDate($due, 'short'));

        if (!$late) {
            return Html::tag('span', $date);
        }

        // Colour is not the only signal — the word is there for anybody who cannot see red.
        return Html::tag('span', $date . ' ' . Html::tag('span', Craft::t('publishr', 'Overdue'), [
            'class' => 'smalltext',
        ]), ['class' => 'error']);
    }

    /** @param int[] $ids */
    private function primeUsers(array $ids): void
    {
        $ids = array_values(array_diff(array_unique($ids), array_keys($this->_users)));

        if ($ids === []) {
            return;
        }

        foreach ($ids as $id) {
            $this->_users[$id] = null;
        }

        foreach (User::find()->id($ids)->status(null)->all() as $user) {
            $this->_users[(int)$user->id] = $user;
        }
    }

    private function user(int $id): ?User
    {
        if (!array_key_exists($id, $this->_users)) {
            $this->primeUsers([$id]);
        }

        return $this->_users[$id];
    }
}
