<?php

declare(strict_types=1);

namespace Noted;

/**
 * Plugin bootstrap and composition root.
 *
 * Owns one Settings instance and hands it to every service that needs to
 * gate behaviour on user role or feature toggles. Side effects only run
 * when {@see Plugin::init()} is invoked from the main plugin file.
 */
final class Plugin
{
    public const VERSION    = '2.0.1';
    public const OPTION_KEY = 'noted_settings';

    private static ?self $instance = null;

    private string $pluginFile;
    private string $pluginDir;
    private Settings $settings;

    /**
     * Get the singleton instance, instantiating it on first call.
     */
    public static function instance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Private constructor — use {@see Plugin::instance()}.
     */
    private function __construct()
    {
        $this->pluginFile = NOTED_PLUGIN_FILE;
        $this->pluginDir  = NOTED_PLUGIN_DIR;
        $this->settings   = new Settings($this);
    }

    /**
     * Register every WordPress hook for the plugin.
     */
    public function init(): void
    {
        Compatibility::register();

        add_action('init', [$this, 'loadTextdomain']);

        $this->settings->register();

        (new PostType())->register();
        (new RestApi($this->settings))->register();
        (new AdminBar($this->settings))->register();
        (new PageNotes($this->settings))->register();
        (new DashboardWidget($this->settings))->register();
        (new BlockNotes())->register();
        (new Assets($this, $this->settings))->register();
    }

    /**
     * Load the plugin text domain so PHP gettext calls resolve. JS
     * translations are wired separately via wp_set_script_translations().
     */
    public function loadTextdomain(): void
    {
        load_plugin_textdomain(
            'noted',
            false,
            dirname(plugin_basename($this->pluginFile)) . '/languages'
        );
    }

    /**
     * Absolute path to the plugin's languages directory.
     */
    public function languagesDir(): string
    {
        return $this->pluginDir . '/languages';
    }

    /**
     * Access the shared Settings instance.
     */
    public function settings(): Settings
    {
        return $this->settings;
    }

    /**
     * Absolute path to the main plugin file (used by plugins_url()).
     */
    public function pluginFile(): string
    {
        return $this->pluginFile;
    }

    /**
     * Absolute path to the plugin directory.
     */
    public function pluginDir(): string
    {
        return $this->pluginDir;
    }

    /**
     * Build a URL for an asset inside the assets/ directory.
     *
     * @param string $relative Path inside assets/ (e.g. "js/api.js").
     */
    public function assetUrl(string $relative): string
    {
        return plugins_url('assets/' . ltrim($relative, '/'), $this->pluginFile);
    }

    /**
     * SVG markup for the plugin icon.
     *
     * Filterable via `noted/icon_svg` — return any inline SVG and every
     * surface that uses the icon (admin bar, block-editor sidebar) picks
     * up the change. The output is sanitised against an SVG allowlist so
     * a third-party filter cannot inject scripts.
     */
    public function iconSvg(): string
    {
        $default  = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path fill-rule="evenodd" clip-rule="evenodd" d="M6.68822 16.625L5.5 17.8145L5.5 5.5L18.5 5.5L18.5 16.625L6.68822 16.625ZM7.31 18.125L19 18.125C19.5523 18.125 20 17.6773 20 17.125L20 5C20 4.44772 19.5523 4 19 4H5C4.44772 4 4 4.44772 4 5V19.5247C4 19.8173 4.16123 20.086 4.41935 20.2237C4.72711 20.3878 5.10601 20.3313 5.35252 20.0845L7.31 18.125ZM16 9.99997H8V8.49997H16V9.99997ZM8 14H13V12.5H8V14Z"/></svg>';
        $filtered = (string) apply_filters('noted/icon_svg', $default);
        return wp_kses($filtered, $this->allowedIconSvgTags());
    }

    /**
     * Allowed SVG tag/attribute map for sanitising icon markup.
     *
     * @return array<string, array<string, bool>>
     */
    private function allowedIconSvgTags(): array
    {
        return [
            'svg'  => [
                'xmlns'         => true,
                'viewbox'       => true,
                'fill'          => true,
                'width'         => true,
                'height'        => true,
                'aria-hidden'   => true,
                'focusable'     => true,
                'role'          => true,
            ],
            'path' => [
                'd'              => true,
                'fill'           => true,
                'fill-rule'      => true,
                'clip-rule'      => true,
                'stroke'         => true,
                'stroke-width'   => true,
                'stroke-linecap' => true,
                'stroke-linejoin'=> true,
            ],
            'g'    => [
                'fill'      => true,
                'transform' => true,
            ],
            'circle' => [
                'cx'           => true,
                'cy'           => true,
                'r'            => true,
                'fill'         => true,
                'stroke'       => true,
                'stroke-width' => true,
            ],
            'rect' => [
                'x'      => true,
                'y'      => true,
                'width'  => true,
                'height' => true,
                'rx'     => true,
                'ry'     => true,
                'fill'   => true,
            ],
            'line' => [
                'x1'           => true,
                'y1'           => true,
                'x2'           => true,
                'y2'           => true,
                'stroke'       => true,
                'stroke-width' => true,
            ],
            'polygon' => [
                'points' => true,
                'fill'   => true,
            ],
            'polyline' => [
                'points'       => true,
                'stroke'       => true,
                'stroke-width' => true,
                'fill'         => true,
            ],
            'title' => [],
            'desc'  => [],
        ];
    }
}
