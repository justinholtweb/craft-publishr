<?php

declare(strict_types=1);

namespace justinholtweb\publishr\gates;

use Craft;
use craft\elements\Entry;
use craft\helpers\Cp;
use craft\helpers\DateTimeHelper;
use justinholtweb\publishr\models\Gate;
use justinholtweb\publishr\models\GateResult;

/**
 * "This has a date on it."
 *
 * Signing a piece off as ready and leaving its post date empty means it goes live the instant
 * somebody enables it — which is usually a surprise to everybody, including whoever enabled it.
 *
 * Optionally requires the date to be in the *future*, which is the rule a desk that batches its
 * sign-offs on a Friday actually wants.
 */
class ScheduledDate extends BaseGateType
{
    public static function handle(): string
    {
        return 'scheduledDate';
    }

    public static function displayName(): string
    {
        return Craft::t('publishr', 'Has a publish date');
    }

    public static function description(): string
    {
        return Craft::t('publishr', 'The entry has a post date, optionally one that hasn’t happened yet.');
    }

    public static function settingsHtml(array $settings): string
    {
        return Cp::lightswitchFieldHtml([
            'label' => Craft::t('publishr', 'Must be in the future'),
            'id' => 'future',
            'name' => 'settings[future]',
            'on' => (bool)($settings['future'] ?? false),
        ]);
    }

    public function check(Entry $entry, Gate $gate): GateResult
    {
        if ($entry->postDate === null) {
            return $this->fail($gate, $this->t('No post date.'));
        }

        if (($gate->settings['future'] ?? false) && $entry->postDate <= DateTimeHelper::now()) {
            return $this->fail($gate, $this->t('The post date has already passed.'));
        }

        return $this->pass($gate, $entry->postDate->format('Y-m-d H:i'));
    }
}
