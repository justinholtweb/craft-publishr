<?php

declare(strict_types=1);

namespace justinholtweb\publishr\models;

use craft\elements\Entry;
use DateTime;

/**
 * One pip on the calendar.
 *
 * The load-bearing decision in the whole calendar: **an entry is not an event.** An entry has up
 * to four editorially interesting dates — when the work is due, when it goes out, when it comes
 * down, and when somebody should look at it again — and a month view that can only show one of
 * them has to pick, which is why every editorial calendar built on `postDate` alone is useless to
 * the person doing the writing. Their deadlines are not post dates.
 *
 * So an entry emits an event per date it has, each on its own lane, and the calendar lets you
 * choose which lanes to see. The same piece can legitimately appear three times in one month, and
 * that is not a bug: it is due on the 4th, out on the 8th, and up for review in November.
 */
class CalendarEvent
{
    public const LANE_DUE = 'due';
    public const LANE_PUBLISH = 'publish';
    public const LANE_EXPIRE = 'expire';
    public const LANE_REVIEW = 'review';

    public const LANES = [self::LANE_DUE, self::LANE_PUBLISH, self::LANE_EXPIRE, self::LANE_REVIEW];

    public function __construct(
        public readonly string $lane,
        public readonly DateTime $date,
        public readonly Entry $entry,
        public readonly ?Item $item = null,

        /** Set when the entry is a draft that has never been published. */
        public readonly bool $isDraft = false,

        /** Set when the date is in the past and the thing that was meant to happen has not. */
        public readonly bool $late = false,
    ) {
    }

    /** `Y-m-d` in the viewing time zone — the key the month grid buckets on. */
    public function dayKey(): string
    {
        return $this->date->format('Y-m-d');
    }

    public function stage(): ?Stage
    {
        return $this->item?->getStage();
    }

    /**
     * The colour this pip gets.
     *
     * Stage colour where there is one, because on a working desk the question a glance at the
     * calendar answers is "how much is still unwritten", not "which lane is this". Lane colour is
     * the fallback for entries Publishr has no record of.
     */
    public function color(): string
    {
        $stage = $this->stage();

        if ($stage !== null) {
            return $stage->color;
        }

        return match ($this->lane) {
            self::LANE_DUE => 'orange',
            self::LANE_PUBLISH => 'green',
            self::LANE_EXPIRE => 'red',
            self::LANE_REVIEW => 'violet',
            default => 'gray',
        };
    }
}
