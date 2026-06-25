<?php

declare(strict_types=1);

namespace Noted;

use WP_Post;

/**
 * The noted_note custom post type and related helpers.
 *
 * Notes are private: not publicly queryable, hidden from search, and the
 * single-post route is redirected to the home URL as a final guard.
 */
final class PostType
{
    public const SLUG = 'noted_note';

    public const META_TIMESTAMP        = '_noted_timestamp';
    public const META_USERNAME         = '_noted_username';
    public const META_MARKDOWN         = '_noted_markdown';
    public const META_ATTACHED_POST_ID = '_noted_attached_post_id';
    public const META_PINNED           = '_noted_pinned';

    /**
     * Register WordPress hooks.
     */
    public function register(): void
    {
        add_action('init', [$this, 'registerPostType']);
        add_action('template_redirect', [$this, 'redirectPrivateNotes']);
    }

    /**
     * Register the custom post type with WordPress.
     */
    public function registerPostType(): void
    {
        register_post_type(self::SLUG, [
            'public'              => false,
            'show_ui'             => false,
            'show_in_rest'        => false,
            'exclude_from_search' => true,
            'publicly_queryable'  => false,
            'capability_type'     => 'post',
            'supports'            => ['title', 'editor', 'custom-fields'],
        ]);
    }

    /**
     * Redirect any direct visit to a single note back to the home URL.
     */
    public function redirectPrivateNotes(): void
    {
        if (is_singular(self::SLUG)) {
            wp_redirect(home_url());
            exit;
        }
    }

    /**
     * Resolve the current post ID — on the admin edit screen or on a
     * frontend singular view (single post / page / CPT).
     *
     * Returns 0 in every other context (list tables, archives, search,
     * settings pages) so the "This Post" tab is hidden where there is
     * no meaningful post to attach to.
     */
    public static function currentPostId(): int
    {
        if (! is_admin() && function_exists('is_singular') && is_singular()) {
            return (int) get_queried_object_id();
        }

        if (! is_admin() || ! function_exists('get_current_screen')) {
            return 0;
        }

        $screen = get_current_screen();
        // List tables also expose $post globally, so we limit the lookup to
        // the post-edit screen ("post" base) to avoid mistaking a row in a
        // list view for the current edit target.
        if (! $screen || $screen->base !== 'post') {
            return 0;
        }

        global $post;
        if ($post instanceof WP_Post) {
            return (int) $post->ID;
        }

        return isset($_GET['post']) ? (int) $_GET['post'] : 0;
    }

    /**
     * Filter out empty notes — those with no title AND no body.
     *
     * @param list<WP_Post> $posts
     * @return list<WP_Post>
     */
    public static function filterRenderable(array $posts): array
    {
        $filtered = array_filter($posts, static function (WP_Post $note): bool {
            return trim($note->post_title) !== '' || trim(wp_strip_all_tags($note->post_content)) !== '';
        });
        return array_values($filtered);
    }

    /**
     * Stable sort that promotes pinned notes to the top, preserving the
     * caller's existing order within each group.
     *
     * @param list<WP_Post> $posts
     * @return list<WP_Post>
     */
    public static function sortPinnedFirst(array $posts): array
    {
        $pinned = [];
        $rest   = [];
        foreach ($posts as $post) {
            if (self::isPinned($post)) {
                $pinned[] = $post;
            } else {
                $rest[] = $post;
            }
        }
        return array_merge($pinned, $rest);
    }

    /**
     * True if a note carries the pinned flag.
     */
    public static function isPinned(WP_Post $note): bool
    {
        return (string) get_post_meta($note->ID, self::META_PINNED, true) === '1';
    }

    /**
     * Serialise a note post for JSON responses.
     *
     * Returns plain (sanitised but un-HTML-encoded) values for text fields;
     * consumers (React `createElement`, jQuery `.text()`/`.attr()`) escape
     * at render time. The HTML body is run through {@see wp_kses_post()}
     * because it is consumed via `dangerouslySetInnerHTML` / `.html()`.
     *
     * @return array<string, mixed>
     */
    public static function format(WP_Post $note): array
    {
        $date     = get_post_meta($note->ID, self::META_TIMESTAMP, true);
        $markdown = (string) get_post_meta($note->ID, self::META_MARKDOWN, true);

        return [
            'id'               => (int) $note->ID,
            'title'            => get_the_title($note),
            'description'      => wp_kses_post($note->post_content),
            'markdown'         => $markdown !== '' ? $markdown : Markdown::toMarkdown($note->post_content),
            'timestamp'        => $date ? date_i18n(get_option('date_format') . ' · ' . get_option('time_format'), strtotime((string) $date)) : '',
            'username'         => (string) get_post_meta($note->ID, self::META_USERNAME, true),
            'attached_post_id' => (int) get_post_meta($note->ID, self::META_ATTACHED_POST_ID, true),
            'pinned'           => self::isPinned($note),
        ];
    }
}
