<?php

declare(strict_types=1);

namespace justinholtweb\publishr\migrations;

use craft\db\Migration;
use craft\db\Table as CraftTable;
use justinholtweb\publishr\records\Table;

/**
 * Publishr's schema.
 *
 * Four shapes here are decisions rather than defaults, and each one is a bug avoided:
 *
 * - **`publishr_items.elementId` is the canonical element ID, and the unique key is
 *   `(elementId, siteId)`.** A piece in progress is a draft, and Craft creates and destroys
 *   provisional drafts constantly. Keying on the draft would scatter one piece's stage across a
 *   dozen rows and lose all of them the moment the draft was applied. Keying on the canonical
 *   entry means the editorial record belongs to the piece.
 *
 * - **`publishr_history` is append-only and separate from the item.** A `stageId` column says
 *   where something is. It cannot say who moved it, when, or from what — and that is the question
 *   a governance tool exists to answer. `dateUpdated` cannot be made to reconstruct it.
 *
 * - **Every FK to `elements` is `CASCADE`, except in history.** Deleting an entry should take its
 *   assignment, its deadline and its comments with it; keeping an orphaned checklist about a page
 *   that no longer exists is clutter nobody asked for. History is the exception and holds a bare
 *   `elementId` with no constraint, because "we published this and then deleted it" is exactly the
 *   kind of thing an audit trail is for.
 *
 * - **`publishr_notifications.dedupeKey` is uniquely indexed.** The sweep runs from cron, from the
 *   queue and from a control-panel request, and all three can fire on the same minute. Making the
 *   insert the thing that has to be won means the losers are told so by the database instead of by
 *   a check-then-write race that sends the same reminder three times.
 */
class Install extends Migration
{
    public function safeUp(): bool
    {
        $this->createConfigTables();
        $this->createWorkflowTables();
        $this->createNotificationTables();
        $this->addForeignKeys();

        return true;
    }

    public function safeDown(): bool
    {
        // Children first — items point at stages and policies, history points at stages.
        $this->dropTableIfExists(Table::NOTIFICATIONS);
        $this->dropTableIfExists(Table::SUBSCRIPTIONS);
        $this->dropTableIfExists(Table::COMMENTS);
        $this->dropTableIfExists(Table::HISTORY);
        $this->dropTableIfExists(Table::ITEMS);
        $this->dropTableIfExists(Table::GATES);
        $this->dropTableIfExists(Table::POLICIES);
        $this->dropTableIfExists(Table::STAGES);

        return true;
    }

    /**
     * Stages, gates and policies mirror project config into the database.
     *
     * So an item can point at a stage with an integer FK, and the CP can sort a board by stage
     * order with a query rather than by walking the YAML on every request.
     */
    private function createConfigTables(): void
    {
        $this->createTable(Table::STAGES, [
            'id' => $this->primaryKey(),
            'name' => $this->string()->notNull(),
            'handle' => $this->string()->notNull(),
            'description' => $this->text(),
            'color' => $this->string(16)->notNull()->defaultValue('gray'),
            'isDefault' => $this->boolean()->notNull()->defaultValue(false),
            'isPublished' => $this->boolean()->notNull()->defaultValue(false),
            'gated' => $this->boolean()->notNull()->defaultValue(false),
            'sortOrder' => $this->integer(),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);

        $this->createIndex(null, Table::STAGES, ['handle'], true);
        $this->createIndex(null, Table::STAGES, ['sortOrder']);

        $this->createTable(Table::GATES, [
            'id' => $this->primaryKey(),
            'name' => $this->string()->notNull(),
            'handle' => $this->string()->notNull(),
            'type' => $this->string()->notNull(),
            'description' => $this->text(),
            'settings' => $this->json(),
            'sectionUids' => $this->json(),
            'stageHandles' => $this->json(),
            'severity' => $this->string(16)->notNull()->defaultValue('required'),
            'enabled' => $this->boolean()->notNull()->defaultValue(true),
            'sortOrder' => $this->integer(),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);

        $this->createIndex(null, Table::GATES, ['handle'], true);

        $this->createTable(Table::POLICIES, [
            'id' => $this->primaryKey(),
            'name' => $this->string()->notNull(),
            'handle' => $this->string()->notNull(),
            'sectionUids' => $this->json(),
            'entryTypeUids' => $this->json(),
            'intervalDays' => $this->integer()->notNull()->defaultValue(180),
            'remindDaysBefore' => $this->integer()->notNull()->defaultValue(14),
            'assignTo' => $this->string(16)->notNull()->defaultValue('current'),
            'assigneeId' => $this->integer(),
            'enabled' => $this->boolean()->notNull()->defaultValue(true),
            'sortOrder' => $this->integer(),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);

        $this->createIndex(null, Table::POLICIES, ['handle'], true);
    }

    private function createWorkflowTables(): void
    {
        $this->createTable(Table::ITEMS, [
            'id' => $this->primaryKey(),
            'elementId' => $this->integer()->notNull(),
            'siteId' => $this->integer()->notNull(),
            'stageId' => $this->integer(),
            'assigneeId' => $this->integer(),

            // When the *work* is due. Deliberately not postDate, which is when it goes out — a
            // calendar that conflates the two cannot show a writer their own deadlines.
            'dueDate' => $this->dateTime(),

            'reviewDue' => $this->dateTime(),
            'lastReviewedAt' => $this->dateTime(),
            'lastReviewedBy' => $this->integer(),
            'policyId' => $this->integer(),
            'brief' => $this->text(),
            'pinned' => $this->boolean()->notNull()->defaultValue(false),

            // Cached gate verdict, so listing 200 items does not run 200 checklists. Believed only
            // while gatesCheckedAt is at or after the element's dateUpdated.
            'gateState' => $this->json(),
            'gatesCheckedAt' => $this->dateTime(),

            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);

        $this->createIndex(null, Table::ITEMS, ['elementId', 'siteId'], true);
        $this->createIndex(null, Table::ITEMS, ['siteId', 'stageId']);
        $this->createIndex(null, Table::ITEMS, ['siteId', 'dueDate']);
        $this->createIndex(null, Table::ITEMS, ['siteId', 'reviewDue']);
        $this->createIndex(null, Table::ITEMS, ['assigneeId']);

        $this->createTable(Table::HISTORY, [
            'id' => $this->primaryKey(),
            'elementId' => $this->integer()->notNull(),
            'siteId' => $this->integer()->notNull(),
            'event' => $this->string(32)->notNull(),
            'userId' => $this->integer(),
            'fromStageId' => $this->integer(),
            'toStageId' => $this->integer(),
            'fromValue' => $this->string(500),
            'toValue' => $this->string(500),
            'note' => $this->text(),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);

        $this->createIndex(null, Table::HISTORY, ['elementId', 'siteId', 'dateCreated']);
        $this->createIndex(null, Table::HISTORY, ['event', 'dateCreated']);
        $this->createIndex(null, Table::HISTORY, ['userId']);

        $this->createTable(Table::COMMENTS, [
            'id' => $this->primaryKey(),
            'elementId' => $this->integer()->notNull(),
            'siteId' => $this->integer()->notNull(),
            'authorId' => $this->integer(),
            'parentId' => $this->integer(),
            'body' => $this->text()->notNull(),
            'resolved' => $this->boolean()->notNull()->defaultValue(false),
            'resolvedBy' => $this->integer(),
            'resolvedAt' => $this->dateTime(),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);

        $this->createIndex(null, Table::COMMENTS, ['elementId', 'siteId', 'dateCreated']);
        $this->createIndex(null, Table::COMMENTS, ['parentId']);
        $this->createIndex(null, Table::COMMENTS, ['resolved']);
    }

    private function createNotificationTables(): void
    {
        $this->createTable(Table::SUBSCRIPTIONS, [
            'id' => $this->primaryKey(),
            'userId' => $this->integer()->notNull(),
            'scope' => $this->string(16)->notNull(),
            'elementId' => $this->integer(),
            'sectionUid' => $this->uid(),
            'events' => $this->json(),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);

        $this->createIndex(null, Table::SUBSCRIPTIONS, ['userId', 'scope']);
        $this->createIndex(null, Table::SUBSCRIPTIONS, ['elementId']);
        $this->createIndex(null, Table::SUBSCRIPTIONS, ['sectionUid']);

        $this->createTable(Table::NOTIFICATIONS, [
            'id' => $this->primaryKey(),
            'userId' => $this->integer()->notNull(),
            'elementId' => $this->integer(),
            'siteId' => $this->integer(),
            'event' => $this->string(32)->notNull(),
            'payload' => $this->json(),
            'status' => $this->string(16)->notNull()->defaultValue('pending'),
            'attempts' => $this->integer()->notNull()->defaultValue(0),
            'error' => $this->text(),
            'sentAt' => $this->dateTime(),

            // The whole race protection. See the class docblock.
            'dedupeKey' => $this->string(255),

            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);

        $this->createIndex(null, Table::NOTIFICATIONS, ['dedupeKey'], true);
        $this->createIndex(null, Table::NOTIFICATIONS, ['status', 'dateCreated']);
        $this->createIndex(null, Table::NOTIFICATIONS, ['userId', 'status']);
    }

    private function addForeignKeys(): void
    {
        $this->addForeignKey(null, Table::ITEMS, ['elementId'], CraftTable::ELEMENTS, ['id'], 'CASCADE', null);
        $this->addForeignKey(null, Table::ITEMS, ['siteId'], CraftTable::SITES, ['id'], 'CASCADE', 'CASCADE');
        $this->addForeignKey(null, Table::ITEMS, ['stageId'], Table::STAGES, ['id'], 'SET NULL', null);
        $this->addForeignKey(null, Table::ITEMS, ['policyId'], Table::POLICIES, ['id'], 'SET NULL', null);
        $this->addForeignKey(null, Table::ITEMS, ['assigneeId'], CraftTable::USERS, ['id'], 'SET NULL', null);
        $this->addForeignKey(null, Table::ITEMS, ['lastReviewedBy'], CraftTable::USERS, ['id'], 'SET NULL', null);

        // No FK on history.elementId — on purpose. "We published this, then deleted it" is exactly
        // what an audit trail is for, and a cascade would erase the record of the deletion.
        $this->addForeignKey(null, Table::HISTORY, ['fromStageId'], Table::STAGES, ['id'], 'SET NULL', null);
        $this->addForeignKey(null, Table::HISTORY, ['toStageId'], Table::STAGES, ['id'], 'SET NULL', null);
        $this->addForeignKey(null, Table::HISTORY, ['userId'], CraftTable::USERS, ['id'], 'SET NULL', null);

        $this->addForeignKey(null, Table::COMMENTS, ['elementId'], CraftTable::ELEMENTS, ['id'], 'CASCADE', null);
        $this->addForeignKey(null, Table::COMMENTS, ['siteId'], CraftTable::SITES, ['id'], 'CASCADE', 'CASCADE');
        $this->addForeignKey(null, Table::COMMENTS, ['authorId'], CraftTable::USERS, ['id'], 'SET NULL', null);
        $this->addForeignKey(null, Table::COMMENTS, ['parentId'], Table::COMMENTS, ['id'], 'CASCADE', null);
        $this->addForeignKey(null, Table::COMMENTS, ['resolvedBy'], CraftTable::USERS, ['id'], 'SET NULL', null);

        $this->addForeignKey(null, Table::SUBSCRIPTIONS, ['userId'], CraftTable::USERS, ['id'], 'CASCADE', null);
        $this->addForeignKey(null, Table::SUBSCRIPTIONS, ['elementId'], CraftTable::ELEMENTS, ['id'], 'CASCADE', null);

        $this->addForeignKey(null, Table::NOTIFICATIONS, ['userId'], CraftTable::USERS, ['id'], 'CASCADE', null);
        $this->addForeignKey(null, Table::NOTIFICATIONS, ['elementId'], CraftTable::ELEMENTS, ['id'], 'CASCADE', null);
    }
}
