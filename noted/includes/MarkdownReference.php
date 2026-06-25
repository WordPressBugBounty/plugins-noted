<?php

declare(strict_types=1);

namespace Noted;

/**
 * Renders the “Supported Markdown” reference for the settings screen.
 *
 * Keeps copy and samples in one place; {@see Settings} only wires the field.
 */
final class MarkdownReference
{
    /**
     * Build the full HTML body for the Markdown reference settings tab.
     */
    public static function settingsTabHtml(): string
    {
        $body = self::examplesHtml() . self::unsupportedSectionHtml();

        return wp_kses_post(sprintf('<div class="noted-md-doc">%1$s</div>', $body));
    }

    /**
     * Render the supported-syntax example blocks.
     */
    private static function examplesHtml(): string
    {
        $output = '';
        foreach (self::exampleSections() as $section) {
            $output .= sprintf(
                '<section class="noted-md-doc__block">
                    <h3 class="noted-md-doc__title">%1$s</h3>
                    <p class="noted-md-doc__intro">%2$s</p>
                    <pre class="noted-md-doc__sample"><code>%3$s</code></pre>
                </section>',
                esc_html($section['title']),
                esc_html($section['intro']),
                esc_html($section['sample'])
            );
        }

        return $output;
    }

    /**
     * @return list<array{title: string, intro: string, sample: string}>
     */
    private static function exampleSections(): array
    {
        return [
            [
                'title'  => __('Headings', 'noted'),
                'intro'  => __('Start the line with one to six # characters, then a space.', 'noted'),
                'sample' => sprintf(
                    "%s\n%s\n%s",
                    '# Main title',
                    '## Section',
                    '### Subsection'
                ),
            ],
            [
                'title'  => __('Bold and italic', 'noted'),
                'intro'  => __('Use asterisks or underscores. These can appear in the middle of a line.', 'noted'),
                'sample' => implode("\n", [
                    '**important**',
                    '__also bold__',
                    '*emphasis*',
                    '_also italic_',
                ]),
            ],
            [
                'title'  => __('Links', 'noted'),
                'intro'  => __(
                    'Labelled links, autolinks in angle brackets, or bare URLs on their own line '
                    . 'or after a space.',
                    'noted'
                ),
                'sample' => sprintf(
                    "%s\n%s\n%s",
                    '[WordPress](https://wordpress.org)',
                    '<https://example.com>',
                    'See https://example.com for details.'
                ),
            ],
            [
                'title'  => __('Lists', 'noted'),
                'intro'  => __(
                    'Unordered lines start with "- " (dash + space). Ordered lines start with "1. ", "2. ", '
                    . 'and so on. Indent with spaces to nest lists.',
                    'noted'
                ),
                'sample' => implode("\n", [
                    '- First item',
                    '- Second item',
                    '  - Nested item',
                    '',
                    '1. Step one',
                    '2. Step two',
                ]),
            ],
        ];
    }

    /**
     * Render the "Not supported" callout listing Markdown features that
     * the lightweight parser intentionally ignores.
     */
    private static function unsupportedSectionHtml(): string
    {
        $items = '';
        foreach (self::unsupportedBullets() as $text) {
            $items .= sprintf('<li>%s</li>', esc_html($text));
        }

        return sprintf(
            '<section class="noted-md-doc__block noted-md-doc__block--muted">
                <h3 class="noted-md-doc__title">%1$s</h3>
                <p class="noted-md-doc__intro">%2$s</p>
                <ul class="noted-md-doc__list">%3$s</ul>
            </section>',
            esc_html__('Not supported', 'noted'),
            esc_html__(
                'Noted does not parse full CommonMark or GitHub-Flavored Markdown. Treat these as plain text:',
                'noted'
            ),
            $items
        );
    }

    /**
     * @return list<string>
     */
    private static function unsupportedBullets(): array
    {
        return [
            __('Fenced code blocks (triple backticks) and inline `code` ticks', 'noted'),
            __('Images, blockquotes, horizontal rules, tables, footnotes', 'noted'),
            __('Strikethrough, task checkboxes, and other extensions', 'noted'),
        ];
    }
}
