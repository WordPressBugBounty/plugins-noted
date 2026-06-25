<?php

declare(strict_types=1);

namespace Noted;

/**
 * Dashboard widget listing every pinned note (read-only).
 *
 * Self-curating by design — users opt notes in by pinning them. When
 * nothing is pinned the widget renders a short prompt instead of an
 * empty list.
 */
final class DashboardWidget
{
    private const WIDGET_ID = 'noted_pinned_notes';

    public function __construct(private Settings $settings) {}

    /**
     * Register WordPress hooks.
     */
    public function register(): void
    {
        add_action('wp_dashboard_setup', [$this, 'registerWidget']);
    }

    /**
     * Register the dashboard widget when the user has view access and
     * the feature is enabled.
     */
    public function registerWidget(): void
    {
        if (! $this->settings->userCanView()) {
            return;
        }
        if (! $this->settings->bool('enable_dashboard_widget', true)) {
            return;
        }

        wp_add_dashboard_widget(
            self::WIDGET_ID,
            __('Pinned Notes', 'noted'),
            [$this, 'render']
        );
    }

    /**
     * Render the widget body.
     */
    public function render(): void
    {
        $pinned = $this->pinnedNotes();

        if (empty($pinned)) {
            ?>
            <p class="noted-empty">
                <?php esc_html_e('Pin a note to see it on your dashboard.', 'noted'); ?>
            </p>
            <?php
            return;
        }
        ?>
        <div class="noted-dashboard-widget">
            <?php foreach ($pinned as $note) :
                $timestamp = get_post_meta($note->ID, PostType::META_TIMESTAMP, true);
                $formatted = $timestamp
                    ? date_i18n(get_option('date_format') . ' · ' . get_option('time_format'), strtotime((string) $timestamp))
                    : '';
                $username  = (string) get_post_meta($note->ID, PostType::META_USERNAME, true);
            ?>
                <article class="noted-card" data-note-id="<?php echo esc_attr((string) $note->ID); ?>">
                    <header class="noted-card__head">
                        <span class="noted-card__title">
                            <?php echo esc_html(get_the_title($note) ?: __('(untitled)', 'noted')); ?>
                        </span>
                    </header>
                    <div class="noted-card__body"><?php echo wp_kses_post($note->post_content); ?></div>
                    <footer class="noted-card__meta">
                        <?php
                        /* translators: 1: WordPress username, 2: Date and time string. */
                        $metaLine = sprintf(__('%1$s · %2$s', 'noted'), $username, $formatted);
                        echo esc_html(trim($metaLine, ' ·'));
                        ?>
                    </footer>
                </article>
            <?php endforeach; ?>
        </div>
        <?php
    }

    /**
     * Fetch every pinned note (any scope), most recent first.
     *
     * @return list<\WP_Post>
     */
    private function pinnedNotes(): array
    {
        $notes = get_posts([
            'post_type'      => PostType::SLUG,
            'posts_per_page' => -1,
            'post_status'    => 'publish',
            'meta_query'     => [[
                'key'   => PostType::META_PINNED,
                'value' => '1',
            ]],
        ]);
        return PostType::filterRenderable($notes);
    }
}
