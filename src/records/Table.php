<?php

declare(strict_types=1);

namespace justinholtweb\publishr\records;

/**
 * Publishr's table names, in one place.
 *
 * Every query and every migration reads its table name from here, so a name written twice cannot
 * drift apart in a typo that only shows up on the one database driver nobody tested.
 */
abstract class Table
{
    /** Editorial stages. A mirror of project config, so an item can point at one with an FK. */
    public const STAGES = '{{%publishr_stages}}';

    /** Publish requirements. Also a project-config mirror. */
    public const GATES = '{{%publishr_gates}}';

    /** Freshness review policies. Also a project-config mirror. */
    public const POLICIES = '{{%publishr_policies}}';

    /** The editorial record for one element, in one site. */
    public const ITEMS = '{{%publishr_items}}';

    /** Append-only log of everything that happened to an item. */
    public const HISTORY = '{{%publishr_history}}';

    /** Editorial comments — the conversation about a piece, never shown to the public. */
    public const COMMENTS = '{{%publishr_comments}}';

    /** Who wants telling about what. */
    public const SUBSCRIPTIONS = '{{%publishr_subscriptions}}';

    /** Outbound notifications, with their own attempt count and error. */
    public const NOTIFICATIONS = '{{%publishr_notifications}}';
}
