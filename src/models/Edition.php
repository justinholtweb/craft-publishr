<?php

declare(strict_types=1);

namespace justinholtweb\publishr\models;

/**
 * What each edition allows.
 *
 * Pure and static, taking `$isPro` rather than reaching for the plugin, so the boundary can be
 * tested without an application and read in one place as the answer to "what exactly does Pro buy".
 *
 * The line: **Lite plans the work. Pro governs it.**
 *
 * Lite is a complete editorial calendar for a team that already trusts each other — the month
 * view, stages of your own, who is doing it, when it is due, the conversation about it, and the
 * history of how it got there. None of that is capped by volume, because charging per entry on a
 * calendar is charging a publisher for publishing.
 *
 * Pro is the half that exists because somebody has to answer for the content: requirements that
 * have to be met before a piece can be signed off, freshness reviews so a page cannot quietly rot
 * for four years, the notifications that make a deadline real, and the reports somebody takes into
 * a meeting. Those are the features that only start to matter when the person who cares about the
 * content is not the person writing it.
 */
class Edition
{
    /**
     * Stages Lite may hold — exactly the five the installer creates.
     *
     * So Lite has a *working* workflow out of the box and can rename, recolour and reorder it.
     * What it cannot do is model an eleven-step approval chain, which is the shape of organisation
     * that is buying governance software anyway.
     */
    public const LITE_MAX_STAGES = 5;

    /** Saved calendar filters ("my desk", "features only") Lite may keep. */
    public const LITE_MAX_SAVED_VIEWS = 2;

    /** Publish requirements — the checklist a piece must satisfy before it can be signed off. */
    public static function allowsGates(bool $isPro): bool
    {
        return $isPro;
    }

    /** Review-by dates, staleness scoring and the policies that set them. */
    public static function allowsFreshness(bool $isPro): bool
    {
        return $isPro;
    }

    /** Email and CP notifications: assigned to you, due tomorrow, overdue, review due. */
    public static function allowsNotifications(bool $isPro): bool
    {
        return $isPro;
    }

    /** The scheduled digest — one mail a morning with the desk's state. */
    public static function allowsDigest(bool $isPro): bool
    {
        return $isPro;
    }

    /** The governance report: coverage, bottlenecks, ageing, who is carrying what. */
    public static function allowsReports(bool $isPro): bool
    {
        return $isPro;
    }

    /** A read-only ICS feed of the calendar, for people who live in Outlook. */
    public static function allowsCalendarFeed(bool $isPro): bool
    {
        return $isPro;
    }

    /** Watching a section rather than an individual piece. */
    public static function allowsSubscriptions(bool $isPro): bool
    {
        return $isPro;
    }

    public static function maxStages(bool $isPro): ?int
    {
        return $isPro ? null : self::LITE_MAX_STAGES;
    }

    public static function maxSavedViews(bool $isPro): ?int
    {
        return $isPro ? null : self::LITE_MAX_SAVED_VIEWS;
    }

    /**
     * Whether another stage may be created, given how many exist.
     *
     * A **downgrade**, not a refusal. A site whose licence lapsed keeps every stage it has and
     * every piece sitting on them; what Lite stops is creating the sixth. Nothing in this class
     * ever moves a piece, deletes a stage, or hides content somebody wrote.
     */
    public static function stageLimitReached(int $existing, bool $isPro): bool
    {
        $max = self::maxStages($isPro);

        return $max !== null && $existing >= $max;
    }
}
