<?php

declare(strict_types=1);

namespace Noted;

use WP_Admin_Bar;

/**
 * The admin-bar Noted! button + the floating side panel HTML.
 *
 * Suppressed on block-editor screens — the {@see EditorSidebar} (JS-side
 * PluginSidebar) is the in-Gutenberg surface, and we don't want both UIs
 * fighting for the same screen real estate.
 */
final class AdminBar
{
    public function __construct(private Settings $settings)
    {
    }

    /**
     * Register WordPress hooks.
     */
    public function register(): void
    {
        add_action('admin_bar_menu', [$this, 'addBarIcon'], 100);
        add_action('admin_footer', [$this, 'renderFloatingPanel']);
        add_action('wp_footer', [$this, 'renderFloatingPanel']);
    }

    /**
     * Add the "Noted!" node to the WordPress admin bar.
     */
    public function addBarIcon(WP_Admin_Bar $bar): void
    {
        if (! $this->shouldRender()) {
            return;
        }
        $bar->add_node([
            'id'    => 'noted',
            'title' => 'Noted!',
            'href'  => '#',
            'meta'  => ['class' => 'noted-icon'],
        ]);
    }

    /**
     * Output the floating panel HTML in the footer.
     */
    public function renderFloatingPanel(): void
    {
        if (! $this->shouldRender()) {
            return;
        }

        $postId = PostType::currentPostId();
        ?>
        <div
            id="noted-panel"
            class="noted-panel wp-admin-styling"
            role="dialog"
            aria-modal="true"
            aria-labelledby="noted-panel-title"
            tabindex="-1"
            data-current-post="<?php echo esc_attr((string) $postId); ?>"
        >
            <button id="noted-close" class="noted-close-button" aria-label="<?php esc_attr_e('Close notes', 'noted'); ?>">&times;</button>
            <div class="noted-content">
                <h2 id="noted-panel-title"><?php esc_html_e('Notes', 'noted'); ?></h2>
                <?php if ($postId) : ?>
                    <div class="noted-tabs" role="tablist">
                        <button type="button" class="noted-tab is-active" data-tab="global" role="tab"><?php esc_html_e('Global', 'noted'); ?></button>
                        <button type="button" class="noted-tab" data-tab="post" role="tab"><?php esc_html_e('This Post', 'noted'); ?></button>
                    </div>
                <?php endif; ?>
                <form id="noted-form">
                    <p class="noted-field">
                        <label for="noted-title"><?php esc_html_e('Title', 'noted'); ?></label>
                        <input type="text" id="noted-title" name="title" class="widefat">
                    </p>
                    <p class="noted-field">
                        <label for="noted-description" class="noted-description-label">
                            <?php esc_html_e('Description', 'noted'); ?>
                            <a
                                class="noted-markdown-doc-link"
                                href="<?php echo esc_url($this->settings->markdownDocumentationUrl()); ?>"
                                target="_blank"
                                rel="noopener noreferrer"
                                aria-label="<?php echo esc_attr($this->settings->markdownDocumentationLinkAriaLabel()); ?>"
                                data-tooltip="<?php echo esc_attr($this->settings->markdownDocumentationIconTooltip()); ?>"
                            >
                                <span class="dashicons dashicons-info" aria-hidden="true"></span>
                            </a>
                        </label>
                        <textarea id="noted-description" name="description" class="widefat" rows="4"></textarea>
                    </p>
                    <button type="button" id="noted-add" class="button button-primary"><?php esc_html_e('Add Note', 'noted'); ?></button>
                </form>
                <div id="noted-list" class="noted-list-container"></div>
            </div>
        </div>
        <?php
    }

    /**
     * True if the floating panel should appear on the current screen.
     *
     * The panel is intentionally shown on block-editor screens too — the
     * PluginSidebar and the floating panel coexist and stay in sync via
     * the `notedApi.subscribe` event channel.
     */
    public function shouldRender(): bool
    {
        if (! $this->settings->userCanView()) {
            return false;
        }
        if (! $this->settings->bool('show_global_panel', true)) {
            return false;
        }
        return true;
    }

    /**
     * Detect whether the current request is an admin block-editor screen.
     */
    public static function isBlockEditorScreen(): bool
    {
        if (! is_admin() || ! function_exists('get_current_screen')) {
            return false;
        }
        $screen = get_current_screen();
        return $screen && method_exists($screen, 'is_block_editor') && $screen->is_block_editor();
    }
}
