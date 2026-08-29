<?php

declare(strict_types=1);

namespace justinholtweb\publishr\integrations;

use Craft;
use craft\base\ElementInterface;
use justinholtweb\publishr\Plugin;
use Throwable;

/**
 * The RedPen adapter.
 *
 * Every reference to RedPen in the plugin goes through this class, and every one of them is a
 * string class name resolved at runtime. Nothing here is a `use` statement pointing into RedPen's
 * namespace — a hard reference would make Publishr's autoloader look for a class that is not
 * installed, and on a site without RedPen that is a fatal error on the *entry editor*, which is
 * the single worst place in a CMS to put one.
 *
 * The rule this follows, and the reason the class exists at all: an optional integration must cost
 * a site that does not have it exactly nothing, including the risk of a crash.
 */
class RedPen
{
    private const PLUGIN_HANDLE = 'redpen';
    private const PLUGIN_CLASS = 'justinholtweb\\redpen\\Plugin';

    private static ?bool $_available = null;

    /** Whether RedPen is installed, enabled, and has the API this adapter uses. */
    public static function isAvailable(): bool
    {
        if (self::$_available !== null) {
            return self::$_available;
        }

        self::$_available = false;

        if (!Craft::$app->getPlugins()->isPluginEnabled(self::PLUGIN_HANDLE)) {
            return false;
        }

        $plugin = Craft::$app->getPlugins()->getPlugin(self::PLUGIN_HANDLE);

        if ($plugin === null || !class_exists(self::PLUGIN_CLASS)) {
            return false;
        }

        // The service, not just the plugin. A future RedPen that renamed `review` would otherwise
        // read as available right up until the first entry somebody tried to sign off.
        //
        // `has()`, not `hasProperty()`. A Craft plugin's services are components registered on the
        // module's service locator and reached through `__get`; `hasProperty()` only knows about
        // real properties, getters and behaviours, so it answers false for every service every
        // plugin has — which is a check that always fails and never looks like it should.
        self::$_available = $plugin instanceof \yii\base\Module && $plugin->has('review');

        return self::$_available;
    }

    /** Forget the cached answer. For tests, and for the moment after RedPen is installed. */
    public static function reset(): void
    {
        self::$_available = null;
    }

    /**
     * Ask RedPen what it thinks of an element.
     *
     * @param string $threshold `error`, `warning` or `any`.
     * @return array{count: int, summary: string}|null Null when RedPen could not answer.
     */
    public static function review(ElementInterface $element, ?string $profileHandle = null, string $threshold = 'error'): ?array
    {
        if (!self::isAvailable()) {
            return null;
        }

        try {
            $redpen = Craft::$app->getPlugins()->getPlugin(self::PLUGIN_HANDLE);
            $profile = null;

            if ($profileHandle !== null && $redpen->has('profiles')) {
                $profile = $redpen->profiles->getProfileByHandle($profileHandle);

                if ($profile === null) {
                    // A profile handle that no longer resolves is a configuration mistake, not a
                    // dirty piece of copy. Reviewing against the default instead would silently
                    // apply the wrong style guide, which is worse than saying nothing.
                    return null;
                }
            }

            $result = $redpen->review->reviewElement($element, $profile);
            $issues = $result->issues ?? [];

            $counted = array_values(array_filter($issues, static function($issue) use ($threshold) {
                $severity = $issue->severity ?? 'error';

                return match ($threshold) {
                    'any' => true,
                    'warning' => in_array($severity, ['error', 'warning'], true),
                    default => $severity === 'error',
                };
            }));

            $summary = implode(' · ', array_slice(array_map(
                static fn($issue) => (string)($issue->message ?? $issue->ruleId ?? ''),
                $counted,
            ), 0, 3));

            return ['count' => count($counted), 'summary' => $summary];
        } catch (Throwable $e) {
            Craft::warning('RedPen review failed: ' . $e->getMessage(), Plugin::LOG_CATEGORY);

            return null;
        }
    }
}
