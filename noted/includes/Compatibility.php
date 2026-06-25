<?php

declare(strict_types=1);

namespace Noted;

/**
 * Third-party plugin integrations that are not core Noted behaviour.
 */
final class Compatibility
{
    /**
     * Register compatibility filters for plugins that aggressively dequeue
     * admin scripts/styles. Adding our slug to their allowlists keeps the
     * Noted! assets loading on screens where they would otherwise be cut.
     */
    public static function register(): void
    {
        add_filter('fluentform/exclude_js_slugs_from_dequeue', [self::class, 'fluentFormsCompatibility']);
        add_filter('mailpoet_conflict_resolver_whitelist_script', [self::class, 'mailpoetCompatibility'], 10, 1);
        add_filter('mailpoet_conflict_resolver_whitelist_style', [self::class, 'mailpoetCompatibility'], 10, 1);
    }

    /**
     * Fluent Forms may dequeue third-party JS in the admin; keep Noted
     * scripts when both plugins are active.
     *
     * @param array<int|string, mixed> $slugs
     * @return array<int|string, mixed>
     */
    public static function fluentFormsCompatibility(array $slugs): array
    {
        $slugs[] = 'noted';

        return $slugs;
    }

    /**
     * MailPoet's conflict resolver may dequeue admin scripts; allow Noted
     * assets when both plugins are active.
     *
     * @param array<int|string, mixed> $patterns
     * @return array<int|string, mixed>
     */
    public static function mailpoetCompatibility(array $patterns): array
    {
        $patterns[] = 'noted';

        return $patterns;
    }
}
