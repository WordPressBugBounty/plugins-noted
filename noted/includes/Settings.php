<?php

declare(strict_types=1);

namespace Noted;

use Wireframe\App as WireframeApp;
use Wireframe\Settings as WireframeSettings;

/**
 * Settings boot + access helpers.
 *
 * Owns the wp-wireframe configuration, exposes typed setting readers
 * for the rest of the plugin, and moves the registered admin menu page
 * from its default top-level location into the Tools submenu.
 *
 * Access is split into two independent role gates:
 *   - {@see Settings::userCanView()}  — read access (panel + REST GET)
 *   - {@see Settings::userCanEdit()}  — write access (REST POST/PUT/DELETE)
 */
final class Settings
{
    public const PAGE_SLUG = 'noted';

    /** Wireframe settings tab: plugin options (default tab). */
    public const TAB_GENERAL = 'general';

    /** Wireframe settings tab: Markdown syntax reference (`?tab=markdown`). */
    public const TAB_MARKDOWN = 'markdown';

    /**
     * Standard WordPress role privilege ranking, most → least powerful.
     * Used to gate access cleanly without remembering capability names.
     */
    private const ROLE_RANK = [
        'administrator' => 5,
        'editor'        => 4,
        'author'        => 3,
        'contributor'   => 2,
        'subscriber'    => 1,
    ];

    public function __construct(private Plugin $plugin)
    {
    }

    /**
     * Register WordPress hooks.
     */
    public function register(): void
    {
        add_action('init', [$this, 'boot']);
        // Priority 999 runs after wp-wireframe has registered its admin menu.
        add_action('admin_menu', [$this, 'relocateMenuToTools'], 999);
    }

    /**
     * Move the wp-wireframe-registered top-level menu under the Tools menu.
     */
    public function relocateMenuToTools(): void
    {
        global $menu, $submenu;

        $slug = self::PAGE_SLUG;

        if (is_array($menu)) {
            foreach ($menu as $key => $item) {
                if (isset($item[2]) && $item[2] === $slug) {
                    unset($menu[$key]);
                    break;
                }
            }
        }

        $submenu['tools.php'][] = [
            'Noted!',
            'manage_options',
            'admin.php?page=' . $slug,
        ];
    }

    /**
     * Boot the wp-wireframe settings page.
     */
    public function boot(): void
    {
        if (! class_exists(WireframeApp::class)) {
            return;
        }

        WireframeApp::boot([
            'prefix'     => self::PAGE_SLUG,
            'page_title' => 'Noted!',
            'option_key' => Plugin::OPTION_KEY,
            'menu_icon'  => 'dashicons-edit-page',
            'config'     => $this->config(),
        ]);
    }

    /**
     * True if the current user can view notes.
     */
    public function userCanView(): bool
    {
        return $this->userHasRoleAtLeast($this->get('min_role_view', 'administrator'));
    }

    /**
     * True if the current user can add / edit / delete notes.
     */
    public function userCanEdit(): bool
    {
        return $this->userHasRoleAtLeast($this->get('min_role_edit', 'administrator'));
    }

    /**
     * True if the current user has the named role or any role above it
     * in {@see Settings::ROLE_RANK}. Custom roles get rank 0 and are only
     * granted access when the gate is set to subscriber (the lowest).
     */
    public function userHasRoleAtLeast(string $minRole): bool
    {
        $user = wp_get_current_user();
        if (! $user || ! $user->exists()) {
            return false;
        }

        $required = self::ROLE_RANK[$minRole] ?? 0;
        foreach ((array) $user->roles as $role) {
            $rank = self::ROLE_RANK[$role] ?? 0;
            if ($rank >= $required) {
                return true;
            }
        }
        return false;
    }

    /**
     * Read a boolean setting with a default fallback.
     */
    public function bool(string $key, bool $default = false): bool
    {
        return (bool) $this->get($key, $default);
    }

    /**
     * Read any setting value via the wp-wireframe facade.
     */
    public function get(string $key, mixed $default = null): mixed
    {
        if (! class_exists(WireframeSettings::class)) {
            return $default;
        }
        return WireframeSettings::get(Plugin::OPTION_KEY, $key, $default);
    }

    /**
     * Build a slug → label list of all editable WordPress roles.
     *
     * Uses translate_user_role() so localized role names appear correctly.
     *
     * @return array<string, string>
     */
    private function roleOptions(): array
    {
        $options = [];
        $roles = function_exists('wp_roles') ? wp_roles()->roles : [];
        foreach ($roles as $slug => $info) {
            $name = isset($info['name']) ? (string) $info['name'] : $slug;
            $options[$slug] = function_exists('translate_user_role')
                ? translate_user_role($name)
                : $name;
        }
        return $options;
    }

    /**
     * Admin URL for the Markdown reference tab (same screen as Noted! settings).
     */
    public function markdownDocumentationUrl(): string
    {
        return add_query_arg(
            [
                'page' => self::PAGE_SLUG,
                'tab'  => self::TAB_MARKDOWN,
            ],
            admin_url('admin.php')
        );
    }

    /**
     * Accessible name for the Markdown help control (icon-only link).
     */
    public function markdownDocumentationLinkAriaLabel(): string
    {
        return sprintf(
            /* translators: 1: Link purpose, 2: Screen reader hint that the link opens a new browser tab. */
            __('%1$s %2$s', 'noted'),
            __('View supported Markdown', 'noted'),
            __('(opens in a new tab)', 'noted')
        );
    }

    /**
     * Hover/focus tooltip for the Markdown help icon (sighted users).
     */
    public function markdownDocumentationIconTooltip(): string
    {
        return __(
            'Limited Markdown Support (titles, bold, italic, lists, & links)',
            'noted'
        );
    }

    /**
     * Build the settings page schema.
     *
     * @return array<string, mixed>
     */
    private function config(): array
    {
        $roleOptions = $this->roleOptions();

        $generalSections = [
            [
                'id'     => 'features',
                'title'  => __('Features', 'noted'),
                'fields' => [
                    [
                        'id'          => 'enable_page_notes',
                        'type'        => 'toggle',
                        'label'       => __('Enable page-level notes', 'noted'),
                        'description' => __('Adds a Notes sidebar to the block editor (and a classic-editor meta box fallback).', 'noted'),
                        'default'     => true,
                    ],
                    [
                        'id'          => 'enable_block_notes',
                        'type'        => 'toggle',
                        'label'       => __('Enable block-level notes', 'noted'),
                        'description' => __('Adds a Note field to every block in the editor. Stored inline on the block.', 'noted'),
                        'default'     => true,
                    ],
                    [
                        'id'          => 'show_global_panel',
                        'type'        => 'toggle',
                        'label'       => __('Show the floating panel', 'noted'),
                        'description' => __('The admin-bar Noted! button that opens the side panel.', 'noted'),
                        'default'     => true,
                    ],
                    [
                        'id'          => 'enable_dashboard_widget',
                        'type'        => 'toggle',
                        'label'       => __('Show pinned notes on the dashboard', 'noted'),
                        'description' => __('Adds a widget to the WordPress dashboard that lists every pinned note.', 'noted'),
                        'default'     => true,
                    ],
                ],
            ],
            [
                'id'          => 'access',
                'title'       => __('Access', 'noted'),
                'description' => __('View grants read-only access. Manage grants full add / edit / delete rights.', 'noted'),
                'fields'      => [
                    [
                        'id'          => 'min_role_view',
                        'type'        => 'select',
                        'label'       => __('Minimum role to view notes', 'noted'),
                        'description' => __('Users at this role and above can see Noted! surfaces.', 'noted'),
                        'default'     => 'administrator',
                        'columns'     => 6,
                        'args'        => ['options' => $roleOptions],
                    ],
                    [
                        'id'          => 'min_role_edit',
                        'type'        => 'select',
                        'label'       => __('Minimum role to manage notes', 'noted'),
                        'description' => __('Users below this role can view notes but cannot add, edit, or delete them.', 'noted'),
                        'default'     => 'administrator',
                        'columns'     => 6,
                        'args'        => ['options' => $roleOptions],
                    ],
                ],
            ],
            [
                'id'          => 'maintenance',
                'title'       => __('Maintenance', 'noted'),
                'description' => __('Back up, restore, or wipe Noted! data.', 'noted'),
                'fields'      => [
                    [
                        'id'          => 'delete_on_uninstall',
                        'type'        => 'toggle',
                        'label'       => __('Delete all data on uninstall', 'noted'),
                        'description' => __('When the plugin is deleted, remove every note, strip block-level notes, and clear plugin settings. Off by default — safe upgrades.', 'noted'),
                        'default'     => false,
                    ],
                    [
                        'id'          => 'settings_export',
                        'type'        => 'export',
                        'label'       => __('Export settings', 'noted'),
                        'description' => __('Download the current settings as JSON.', 'noted'),
                        'columns'     => 6,
                        'args'        => [
                            'button_label' => __('Download JSON', 'noted'),
                            'filename'     => 'noted-settings',
                        ],
                    ],
                    [
                        'id'          => 'settings_import',
                        'type'        => 'import',
                        'label'       => __('Import settings', 'noted'),
                        'description' => __('Upload a previously exported JSON file to restore settings.', 'noted'),
                        'columns'     => 6,
                        'args'        => [
                            'button_label' => __('Upload JSON', 'noted'),
                        ],
                    ],
                ],
            ],
        ];

        return [
            'title'    => __('Noted! Settings', 'noted'),
            'subtitle' => __('Control where notes appear and who can see them.', 'noted'),
            'tabs'     => [
                [
                    'id'       => self::TAB_GENERAL,
                    'title'    => __('Settings', 'noted'),
                    'sections' => $generalSections,
                ],
                [
                    'id'       => self::TAB_MARKDOWN,
                    'title'    => __('Markdown', 'noted'),
                    'sections' => [
                        [
                            'id'          => 'markdown_reference',
                            'title'       => __('Supported Markdown in notes', 'noted'),
                            'description' => __(
                                'Use these patterns in page notes, the floating panel, and anywhere else Noted stores a Markdown note body.',
                                'noted'
                            ),
                            'fields'      => [
                                [
                                    'id'      => 'markdown_doc_intro',
                                    'type'    => 'html',
                                    'label'   => '',
                                    'columns' => 12,
                                    'args'    => [
                                        'variant' => 'info',
                                        'content' => wp_kses_post(
                                            '<p>'
                                            . esc_html__(
                                                'Noted converts a small subset of Markdown into safe HTML. It is not a full Markdown specification — just the essentials for clear notes.',
                                                'noted'
                                            )
                                            . '</p>'
                                        ),
                                    ],
                                ],
                                [
                                    'id'      => 'markdown_doc_reference',
                                    'type'    => 'html',
                                    'label'   => '',
                                    'columns' => 12,
                                    'args'    => [
                                        'variant' => 'plain',
                                        'content' => MarkdownReference::settingsTabHtml(),
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];
    }
}
