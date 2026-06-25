<?php
/**
 * Noted! — uninstall handler.
 *
 * Runs when the plugin is deleted from the WordPress admin (NOT on
 * deactivation). Only purges data when the user has opted in via the
 * "Delete all data on uninstall" toggle on the settings page.
 */

declare(strict_types=1);

if (! defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

const NOTED_OPTION_KEY = 'noted_settings';
const NOTED_POST_TYPE  = 'noted_note';

$noted_settings = get_option(NOTED_OPTION_KEY, []);
if (! is_array($noted_settings) || empty($noted_settings['delete_on_uninstall'])) {
    return;
}

global $wpdb;

// 1. Delete every noted_note post and its associated meta.

$note_ids = $wpdb->get_col($wpdb->prepare(
    "SELECT ID FROM {$wpdb->posts} WHERE post_type = %s",
    NOTED_POST_TYPE
));
foreach ((array) $note_ids as $note_id) {
    wp_delete_post((int) $note_id, true);
}

// 2. Strip the `notedNote` block attribute from every post that contains it.

$strip_noted_note = static function (array $block) use (&$strip_noted_note): array {
    if (isset($block['attrs']['notedNote'])) {
        unset($block['attrs']['notedNote']);
    }
    if (isset($block['attrs']['notedNoteUser'])) {
        unset($block['attrs']['notedNoteUser']);
    }
    if (! empty($block['innerBlocks'])) {
        $block['innerBlocks'] = array_map($strip_noted_note, $block['innerBlocks']);
    }
    return $block;
};

$attribute_marker = '"notedNote"';
$like_pattern     = '%' . $wpdb->esc_like($attribute_marker) . '%';
$candidate_ids    = $wpdb->get_col($wpdb->prepare(
    "SELECT ID FROM {$wpdb->posts} WHERE post_content LIKE %s",
    $like_pattern
));

foreach ((array) $candidate_ids as $post_id) {
    $original_content = get_post_field('post_content', (int) $post_id);
    if (! is_string($original_content) || strpos($original_content, $attribute_marker) === false) {
        continue;
    }

    $blocks            = parse_blocks($original_content);
    $cleaned_blocks    = array_map($strip_noted_note, $blocks);
    $rewritten_content = serialize_blocks($cleaned_blocks);

    if ($rewritten_content === $original_content) {
        continue;
    }

    wp_update_post([
        'ID'           => (int) $post_id,
        'post_content' => $rewritten_content,
    ]);
}

// 3. Drop the plugin option.

delete_option(NOTED_OPTION_KEY);
