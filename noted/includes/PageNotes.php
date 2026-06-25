<?php

declare(strict_types=1);

namespace Noted;

use WP_Post;

/**
 * Classic-editor meta box for post-scoped notes.
 *
 * Behaves as the fallback when the block editor is not active. The Gutenberg
 * equivalent (a PluginSidebar) is registered entirely on the JS side.
 */
final class PageNotes
{
    public function __construct(private Settings $settings) {}

    /**
     * Register WordPress hooks.
     */
    public function register(): void
    {
        add_action('add_meta_boxes', [$this, 'registerMetaBox']);
    }

    /**
     * Register the meta box for every public post type that isn't a note itself.
     */
    public function registerMetaBox(string $postType): void
    {
        if (! $this->settings->userCanView()) {
            return;
        }
        if (! $this->settings->bool('enable_page_notes', true)) {
            return;
        }
        if ($postType === PostType::SLUG) {
            return;
        }
        // The PluginSidebar covers this on block-editor screens.
        if (AdminBar::isBlockEditorScreen()) {
            return;
        }

        $obj = get_post_type_object($postType);
        if (! $obj || ! $obj->public) {
            return;
        }

        add_meta_box(
            'noted_page_notes',
            __('Notes', 'noted'),
            [$this, 'renderMetaBox'],
            $postType,
            'side',
            'default'
        );
    }

    /**
     * Render the meta box for a given post.
     */
    public function renderMetaBox(WP_Post $post): void
    {
        $notes = get_posts([
            'post_type'      => PostType::SLUG,
            'posts_per_page' => -1,
            'post_status'    => 'publish',
            'meta_query'     => [[
                'key'   => PostType::META_ATTACHED_POST_ID,
                'value' => $post->ID,
            ]],
        ]);
        $notes = PostType::sortPinnedFirst(PostType::filterRenderable($notes));
        ?>
        <div class="noted-metabox" data-post-id="<?php echo esc_attr((string) $post->ID); ?>">
            <div class="noted-metabox-list">
                <?php if (empty($notes)) : ?>
                    <p class="noted-empty"><?php esc_html_e('No notes on this post yet.', 'noted'); ?></p>
                <?php else : ?>
                    <?php foreach ($notes as $note) :
                        $username  = (string) get_post_meta($note->ID, PostType::META_USERNAME, true);
                        $timestamp = get_post_meta($note->ID, PostType::META_TIMESTAMP, true);
                        $formatted = $timestamp ? date_i18n(get_option('date_format') . ' · ' . get_option('time_format'), strtotime($timestamp)) : '';
                        $isPinned  = PostType::isPinned($note);
                    ?>
                        <?php $markdown = (string) get_post_meta($note->ID, PostType::META_MARKDOWN, true); ?>
                        <article
                            class="noted-card noted-card--collapsible<?php echo $isPinned ? ' is-pinned' : ''; ?>"
                            data-note-id="<?php echo esc_attr((string) $note->ID); ?>"
                            data-markdown="<?php echo esc_attr($markdown); ?>"
                            data-title="<?php echo esc_attr(get_the_title($note)); ?>"
                        >
                            <header class="noted-card__head">
                                <button
                                    type="button"
                                    id="noted-toggle-<?php echo (int) $note->ID; ?>"
                                    class="noted-card__title"
                                    aria-expanded="false"
                                    aria-controls="noted-collapsible-<?php echo (int) $note->ID; ?>"
                                ><?php echo esc_html(get_the_title($note) ?: __('(untitled)', 'noted')); ?></button>
                            </header>
                            <div
                                class="noted-card__collapsible"
                                id="noted-collapsible-<?php echo (int) $note->ID; ?>"
                                role="region"
                                aria-labelledby="noted-toggle-<?php echo (int) $note->ID; ?>"
                            >
                                <div class="noted-card__body"><?php echo wp_kses_post($note->post_content); ?></div>
                                <div class="noted-card__actions">
                                    <button type="button" class="button-link noted-metabox-pin" data-note-id="<?php echo esc_attr((string) $note->ID); ?>" aria-pressed="<?php echo $isPinned ? 'true' : 'false'; ?>">
                                        <?php echo $isPinned ? esc_html__('Unpin', 'noted') : esc_html__('Pin', 'noted'); ?>
                                    </button>
                                    <button type="button" class="button-link noted-metabox-edit" data-note-id="<?php echo esc_attr((string) $note->ID); ?>">
                                        <?php esc_html_e('Edit', 'noted'); ?>
                                    </button>
                                    <button type="button" class="button-link button-link-delete noted-metabox-delete" data-note-id="<?php echo esc_attr((string) $note->ID); ?>">
                                        <?php esc_html_e('Delete', 'noted'); ?>
                                    </button>
                                </div>
                                <footer class="noted-card__meta">
                                    <?php
                                    /* translators: 1: WordPress username, 2: Date and time string. */
                                    $metaLine = sprintf(__('%1$s · %2$s', 'noted'), $username, $formatted);
                                    echo esc_html(trim($metaLine, ' ·'));
                                    ?>
                                </footer>
                            </div>
                        </article>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
            <div class="noted-metabox-add">
                <p><input type="text" class="noted-metabox-title widefat" placeholder="<?php esc_attr_e('Title', 'noted'); ?>"></p>
                <p class="noted-metabox-description-wrap">
                    <label class="screen-reader-text" for="noted-metabox-description-<?php echo (int) $post->ID; ?>"><?php esc_html_e('Description', 'noted'); ?></label>
                    <textarea id="noted-metabox-description-<?php echo (int) $post->ID; ?>" class="noted-metabox-description widefat" rows="4" placeholder="<?php esc_attr_e('Write your note…', 'noted'); ?>"></textarea>
                    <a
                        class="noted-markdown-doc-link noted-metabox-markdown-help"
                        href="<?php echo esc_url($this->settings->markdownDocumentationUrl()); ?>"
                        target="_blank"
                        rel="noopener noreferrer"
                        aria-label="<?php echo esc_attr($this->settings->markdownDocumentationLinkAriaLabel()); ?>"
                        data-tooltip="<?php echo esc_attr($this->settings->markdownDocumentationIconTooltip()); ?>"
                    >
                        <span class="dashicons dashicons-info" aria-hidden="true"></span>
                    </a>
                </p>
                <p><button type="button" class="button button-primary noted-metabox-add-btn"><?php esc_html_e('Add Note', 'noted'); ?></button></p>
            </div>
        </div>
        <?php
    }
}
