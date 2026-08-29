<?php

declare(strict_types=1);

namespace justinholtweb\publishr\gates;

use Craft;
use craft\elements\Entry;
use craft\helpers\Cp;
use justinholtweb\publishr\integrations\RedPen;
use justinholtweb\publishr\models\Gate;
use justinholtweb\publishr\models\GateResult;

/**
 * "The copy passes the house style guide."
 *
 * Publishr does not check prose. RedPen does, it does it well, and duplicating a rule engine so
 * that two plugins could disagree about whether "utilise" is acceptable would be the worst
 * possible outcome for a customer who owns both. So this gate is a thin adapter: it asks RedPen
 * for a verdict on the entry and turns it into a pass or a fail.
 *
 * Unavailable — not failing — when RedPen is not installed. A site that buys Publishr on Monday
 * and RedPen in March gets a requirement that lights up on its own; a site that never buys RedPen
 * never sees a red cross it cannot clear.
 */
class QualityReview extends BaseGateType
{
    public static function handle(): string
    {
        return 'qualityReview';
    }

    public static function displayName(): string
    {
        return Craft::t('publishr', 'Passes RedPen');
    }

    public static function description(): string
    {
        return Craft::t('publishr', 'The copy is clean against your RedPen style guide.');
    }

    public static function isAvailable(): bool
    {
        return RedPen::isAvailable();
    }

    public static function unavailableReason(): ?string
    {
        return self::isAvailable()
            ? null
            : Craft::t('publishr', 'RedPen isn’t installed. This requirement is skipped until it is.');
    }

    public static function settingsHtml(array $settings): string
    {
        return Cp::selectFieldHtml([
                'label' => Craft::t('publishr', 'Fail on'),
                'instructions' => Craft::t('publishr', 'Which RedPen issues count as a failure.'),
                'id' => 'threshold',
                'name' => 'settings[threshold]',
                'options' => [
                    ['label' => Craft::t('publishr', 'Errors only'), 'value' => 'error'],
                    ['label' => Craft::t('publishr', 'Errors and warnings'), 'value' => 'warning'],
                    ['label' => Craft::t('publishr', 'Anything at all'), 'value' => 'any'],
                ],
                'value' => $settings['threshold'] ?? 'error',
            ]) . Cp::textFieldHtml([
                'label' => Craft::t('publishr', 'RedPen profile'),
                'instructions' => Craft::t('publishr', 'Handle of the profile to review against. Blank uses the one RedPen picks for the entry.'),
                'id' => 'profile',
                'name' => 'settings[profile]',
                'value' => $settings['profile'] ?? '',
            ]);
    }

    public function check(Entry $entry, Gate $gate): GateResult
    {
        if (!RedPen::isAvailable()) {
            return $this->skip($gate, (string)self::unavailableReason());
        }

        $threshold = (string)($gate->settings['threshold'] ?? 'error');
        $profile = trim((string)($gate->settings['profile'] ?? '')) ?: null;

        $verdict = RedPen::review($entry, $profile, $threshold);

        if ($verdict === null) {
            // RedPen is installed but could not answer — its LLM backend timed out, or the profile
            // named here no longer exists. Not a failure: the copy has not been found wanting, it
            // has not been read.
            return $this->skip($gate, $this->t('RedPen couldn’t review this piece.'));
        }

        if ($verdict['count'] === 0) {
            return $this->pass($gate, $this->t('Clean'));
        }

        return $this->fail(
            $gate,
            $this->t('{n, plural, =1{One issue from RedPen} other{# issues from RedPen}}', ['n' => $verdict['count']]),
            $verdict['summary'],
        );
    }
}
