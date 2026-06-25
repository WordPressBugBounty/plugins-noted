<?php

declare(strict_types=1);

namespace Noted;

/**
 * Asset registration and enqueueing.
 *
 * One stylesheet (noted.css) plus five JS modules:
 *   - api.js              — shared REST client (window.notedApi)
 *   - global-panel.js     — floating panel (admin + frontend, jQuery)
 *   - editor-sidebar.js   — Gutenberg PluginSidebar
 *   - block-notes.js      — block-level note attribute + InspectorControls
 *   - classic-metabox.js  — classic editor meta box handler
 *
 * Every JS consumer depends on the `noted-api` handle so the REST wrapper
 * lives in exactly one place. Cache-busting versions come from each file's
 * mtime so a save invalidates the URL automatically.
 */
final class Assets
{
    private const HANDLE_API             = 'noted-api';
    private const HANDLE_ICONS           = 'noted-icons';
    private const HANDLE_CSS             = 'noted-css';
    private const HANDLE_GLOBAL_PANEL    = 'noted-global-panel';
    private const HANDLE_EDITOR_SIDEBAR  = 'noted-editor-sidebar';
    private const HANDLE_BLOCK_NOTES     = 'noted-block-notes';
    private const HANDLE_CLASSIC_METABOX = 'noted-classic-metabox';

    public function __construct(
        private Plugin $plugin,
        private Settings $settings,
    ) {}

    /**
     * Register WordPress hooks.
     */
    public function register(): void
    {
        add_action('admin_enqueue_scripts', [$this, 'enqueueGeneral']);
        add_action('wp_enqueue_scripts', [$this, 'enqueueGeneral']);
        add_action('enqueue_block_editor_assets', [$this, 'enqueueBlockEditor']);
        add_action('admin_enqueue_scripts', [$this, 'enqueueClassicMetabox']);
    }

    /**
     * Stylesheet + floating-panel JS for admin (non-block-editor) and frontend.
     */
    public function enqueueGeneral(): void
    {
        if (! is_user_logged_in()) {
            return;
        }
        if (! $this->settings->userCanView()) {
            return;
        }

        $this->registerSharedApi();

        // `buttons` is a core style handle that provides .button / .button-primary
        // / .button-link / .button-link-delete. Safe to load on the frontend
        // because its selectors are scoped to those classes.
        wp_enqueue_style(
            self::HANDLE_CSS,
            $this->plugin->assetUrl('css/noted.css'),
            ['buttons'],
            $this->assetVersion('css/noted.css')
        );

        if (! $this->shouldRenderGlobalPanel()) {
            return;
        }

        wp_enqueue_style('dashicons');

        wp_enqueue_script(
            self::HANDLE_GLOBAL_PANEL,
            $this->plugin->assetUrl('js/global-panel.js'),
            ['jquery', 'wp-i18n', self::HANDLE_API],
            $this->assetVersion('js/global-panel.js'),
            true
        );
        $this->setTranslations(self::HANDLE_GLOBAL_PANEL);
    }

    /**
     * Block editor: PluginSidebar + block-level notes.
     */
    public function enqueueBlockEditor(): void
    {
        if (! $this->settings->userCanView()) {
            return;
        }

        $this->registerSharedApi();
        $this->registerSharedIcons();

        if ($this->settings->bool('enable_page_notes', true)) {
            wp_enqueue_style('dashicons');
            wp_enqueue_script(
                self::HANDLE_EDITOR_SIDEBAR,
                $this->plugin->assetUrl('js/editor-sidebar.js'),
                [
                    self::HANDLE_API,
                    self::HANDLE_ICONS,
                    'wp-plugins',
                    'wp-editor',
                    'wp-edit-post',
                    'wp-element',
                    'wp-components',
                    'wp-data',
                    'wp-i18n',
                ],
                $this->assetVersion('js/editor-sidebar.js'),
                true
            );
            $this->setTranslations(self::HANDLE_EDITOR_SIDEBAR);
        }

        if ($this->settings->bool('enable_block_notes', true)) {
            wp_enqueue_script(
                self::HANDLE_BLOCK_NOTES,
                $this->plugin->assetUrl('js/block-notes.js'),
                [
                    self::HANDLE_API,
                    self::HANDLE_ICONS,
                    'wp-blocks',
                    'wp-element',
                    'wp-components',
                    'wp-block-editor',
                    'wp-data',
                    'wp-hooks',
                    'wp-compose',
                    'wp-i18n',
                ],
                $this->assetVersion('js/block-notes.js'),
                true
            );
            $this->setTranslations(self::HANDLE_BLOCK_NOTES);
        }
    }

    /**
     * Register the shared icons handle (idempotent).
     */
    private function registerSharedIcons(): void
    {
        if (wp_script_is(self::HANDLE_ICONS, 'registered')) {
            return;
        }

        // `wp-icons` isn't a separate script handle in core, so we ship the
        // SVG markup from PHP via wp_localize_script. Swap the string in
        // Plugin::iconSvg() (or via the `noted/icon_svg` filter) to change
        // the icon everywhere.
        wp_register_script(
            self::HANDLE_ICONS,
            $this->plugin->assetUrl('js/icons.js'),
            ['wp-element'],
            $this->assetVersion('js/icons.js'),
            true
        );

        wp_localize_script(self::HANDLE_ICONS, 'notedIconsConfig', [
            'svg' => $this->plugin->iconSvg(),
        ]);
    }

    /**
     * Classic editor meta box JS, post-edit screens only.
     */
    public function enqueueClassicMetabox(string $hook): void
    {
        if (! in_array($hook, ['post.php', 'post-new.php'], true)) {
            return;
        }
        if (! $this->settings->userCanView()) {
            return;
        }
        if (! $this->settings->bool('enable_page_notes', true)) {
            return;
        }
        if (AdminBar::isBlockEditorScreen()) {
            return;
        }

        $this->registerSharedApi();

        wp_enqueue_script(
            self::HANDLE_CLASSIC_METABOX,
            $this->plugin->assetUrl('js/classic-metabox.js'),
            ['wp-i18n', self::HANDLE_API],
            $this->assetVersion('js/classic-metabox.js'),
            true
        );
        $this->setTranslations(self::HANDLE_CLASSIC_METABOX);
    }

    /**
     * Wire up JS translations for a handle, pointing at the plugin's
     * languages directory so JSON translation files load at runtime.
     */
    private function setTranslations(string $handle): void
    {
        wp_set_script_translations($handle, 'noted', $this->plugin->languagesDir());
    }

    /**
     * Register and localise the shared notedApi handle exactly once.
     */
    private function registerSharedApi(): void
    {
        if (wp_script_is(self::HANDLE_API, 'registered')) {
            return;
        }

        wp_register_script(
            self::HANDLE_API,
            $this->plugin->assetUrl('js/api.js'),
            [],
            $this->assetVersion('js/api.js'),
            true
        );

        wp_localize_script(self::HANDLE_API, 'notedConfig', [
            'rest_root'               => esc_url_raw(rest_url(RestApi::NAMESPACE . '/')),
            'rest_nonce'              => wp_create_nonce('wp_rest'),
            'current_post_id'         => PostType::currentPostId(),
            'can_edit'                => $this->settings->userCanEdit(),
            'markdown_doc_url'        => $this->settings->markdownDocumentationUrl(),
            'markdown_doc_aria_label' => $this->settings->markdownDocumentationLinkAriaLabel(),
            'markdown_doc_tooltip'    => $this->settings->markdownDocumentationIconTooltip(),
        ]);
    }

    /**
     * True if the floating panel should render — mirrors AdminBar.
     */
    private function shouldRenderGlobalPanel(): bool
    {
        return (bool) $this->settings->bool('show_global_panel', true);
    }

    /**
     * Cache-busting version derived from the asset's mtime. Falls back to
     * the plugin version constant if the file can't be stat'd.
     */
    private function assetVersion(string $relative): string
    {
        $path = $this->plugin->pluginDir() . '/assets/' . ltrim($relative, '/');
        if (! file_exists($path)) {
            return Plugin::VERSION;
        }
        return (string) filemtime($path);
    }
}
