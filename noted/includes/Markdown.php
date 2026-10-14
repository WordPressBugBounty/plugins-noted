<?php

declare(strict_types=1);

namespace Noted;

/**
 * Lightweight Markdown <-> HTML converter for note bodies.
 *
 * Supports headings, bold/italic, inline code, links, and (nested) ordered
 * and unordered lists. Not a full Markdown spec — just enough for the note UX.
 */
final class Markdown
{
    /**
     * Placeholder delimiters (Unicode private-use characters) for spans that
     * must not be touched by the other patterns: inline code and URLs.
     */
    private const TOKEN_OPEN  = "\u{E000}";
    private const TOKEN_CLOSE = "\u{E001}";

    /**
     * Convert a Markdown-flavoured string to safe HTML.
     *
     * The final output is run through {@see wp_kses_post()}, so even a
     * regex that accidentally lets a stray tag through is contained at
     * the boundary.
     */
    public static function toHtml(string $text): string
    {
        $text = str_replace(["\r\n", "\r", self::TOKEN_OPEN, self::TOKEN_CLOSE], ["\n", "\n", '', ''], $text);
        $text = trim($text);

        // Code spans and URLs are swapped for placeholders first, so their
        // contents (field_names, ?a=1&b=2, /some_path_/) can't be read as
        // emphasis or headings, then restored at the end.
        $tokens  = [];
        $protect = static function (string $html) use (&$tokens): string {
            $tokens[] = $html;
            return self::TOKEN_OPEN . (count($tokens) - 1) . self::TOKEN_CLOSE;
        };

        $text = preg_replace_callback(
            '/`([^`\n]+)`/',
            static fn (array $m): string => $protect('<code>' . esc_html($m[1]) . '</code>'),
            $text
        );
        $text = preg_replace_callback(
            '/<(https?:\/\/[^\s<>]+)>/',
            static fn (array $m): string => $protect('<a href="' . esc_url($m[1]) . '">' . esc_html($m[1]) . '</a>'),
            $text
        );
        $text = preg_replace_callback(
            '/\[([^\]\n]*)\]\(([^)\s]*)\)/',
            static fn (array $m): string => '[' . $m[1] . '](' . $protect(esc_url($m[2])) . ')',
            $text
        );
        $text = preg_replace_callback(
            // Trailing punctuation ("see https://x.com.") stays outside the link.
            '/(^|[\s>(])(https?:\/\/[^\s<]*[^\s<.,;:!?\'")\]])/m',
            static fn (array $m): string => $m[1] . $protect('<a href="' . esc_url($m[2]) . '">' . esc_html($m[2]) . '</a>'),
            $text
        );

        $inlinePatterns = [
            // Headings only at the start of a line, so "issue #42" stays text.
            '/^\#{6}\s?(.*)$/m'                                                    => '<h6>$1</h6>',
            '/^\#{5}\s?(.*)$/m'                                                    => '<h5>$1</h5>',
            '/^\#{4}\s?(.*)$/m'                                                    => '<h4>$1</h4>',
            '/^\#{3}\s?(.*)$/m'                                                    => '<h3>$1</h3>',
            '/^\#{2}\s?(.*)$/m'                                                    => '<h2>$1</h2>',
            '/^\#{1}\s?(.*)$/m'                                                    => '<h1>$1</h1>',
            '/\*\*(?=\S)(.+?)(?<=\S)\*\*/u'                                        => '<strong>$1</strong>',
            // Underscore emphasis never starts or ends inside a word (snake_case).
            '/(?<![\p{L}\p{N}_])__(?=\S)(.+?)(?<=\S)__(?![\p{L}\p{N}_])/u'          => '<strong>$1</strong>',
            '/\*(?=\S)(.+?)(?<=\S)\*/u'                                            => '<em>$1</em>',
            '/(?<![\p{L}\p{N}_])_(?=\S)(.+?)(?<=\S)_(?![\p{L}\p{N}_])/u'            => '<em>$1</em>',
            '/\[(.*?)\]\((' . self::TOKEN_OPEN . '\d+' . self::TOKEN_CLOSE . ')\)/u' => '<a href="$2">$1</a>',
        ];

        $html  = preg_replace(array_keys($inlinePatterns), array_values($inlinePatterns), $text);
        $lines = explode("\n", $html);

        $output        = '';
        $listStack     = [];
        $currentIndent = 0;

        foreach ($lines as $line) {
            preg_match('/^(\s*)/', $line, $leading);
            $indent = strlen($leading[0]);
            $line   = ltrim($line);

            if (preg_match('/^\d+\.\s+(.*)$/', $line, $matches)) {
                [$output, $listStack, $currentIndent] = self::handleListItem(
                    'ol',
                    $matches[1],
                    $indent,
                    $output,
                    $listStack,
                    $currentIndent,
                );
                continue;
            }

            if (preg_match('/^-\s+(.*)$/', $line, $matches)) {
                [$output, $listStack, $currentIndent] = self::handleListItem(
                    'ul',
                    $matches[1],
                    $indent,
                    $output,
                    $listStack,
                    $currentIndent,
                );
                continue;
            }

            while (! empty($listStack)) {
                $list    = array_pop($listStack);
                $output .= $list['type'] === 'ul' ? "</ul>\n" : "</ol>\n";
            }
            $currentIndent = 0;
            $output      .= $line . "\n";
        }

        while (! empty($listStack)) {
            $list    = array_pop($listStack);
            $output .= $list['type'] === 'ul' ? "</ul>\n" : "</ol>\n";
        }

        // Collapse runs of 3+ blank lines down to a single paragraph break
        // so wpautop produces clean <p> separators rather than ragged gaps.
        $output = preg_replace('/\n{3,}/', "\n\n", $output);
        $output = wpautop(trim($output));
        $output = preg_replace_callback(
            '/' . self::TOKEN_OPEN . '(\d+)' . self::TOKEN_CLOSE . '/u',
            static fn (array $m): string => $tokens[(int) $m[1]] ?? '',
            $output
        );

        return wp_kses_post($output);
    }

    /**
     * Convert plugin-generated HTML back to Markdown.
     *
     * Best-effort — used to round-trip note bodies during edits.
     */
    public static function toMarkdown(string $html): string
    {
        $markdown = $html;

        // Code spans become placeholders until the end, so the tag-strip
        // step can't eat code like `<div>` and entities come back decoded.
        $codes    = [];
        $markdown = preg_replace_callback(
            '/<code>(.*?)<\/code>/s',
            static function (array $m) use (&$codes): string {
                $codes[] = '`' . html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8') . '`';
                return self::TOKEN_OPEN . (count($codes) - 1) . self::TOKEN_CLOSE;
            },
            $markdown
        );

        // Paragraph and break tags become explicit newlines before the
        // generic tag-strip step runs, otherwise paragraph separators get
        // swallowed and the source becomes one long run-on line.
        $markdown = preg_replace('/<p[^>]*>/i', '', $markdown);
        $markdown = preg_replace('/<\/p\s*>/i', "\n\n", $markdown);
        $markdown = preg_replace('/<br\s*\/?>/i', "\n", $markdown);

        $markdown = preg_replace('/<h6[^>]*>(.*?)<\/h6>/', "\n###### $1\n", $markdown);
        $markdown = preg_replace('/<h5[^>]*>(.*?)<\/h5>/', "\n##### $1\n", $markdown);
        $markdown = preg_replace('/<h4[^>]*>(.*?)<\/h4>/', "\n#### $1\n", $markdown);
        $markdown = preg_replace('/<h3[^>]*>(.*?)<\/h3>/', "\n### $1\n", $markdown);
        $markdown = preg_replace('/<h2[^>]*>(.*?)<\/h2>/', "\n## $1\n", $markdown);
        $markdown = preg_replace('/<h1[^>]*>(.*?)<\/h1>/', "\n# $1\n", $markdown);

        $markdown = preg_replace('/<strong>(.*?)<\/strong>/', '**$1**', $markdown);
        // Asterisks, not underscores: "a_b_c" no longer reads as emphasis.
        $markdown = preg_replace('/<em>(.*?)<\/em>/', '*$1*', $markdown);

        // A bare URL (not <url>, which the tag-strip below would remove).
        $markdown = preg_replace('/<a href="(.*?)">\1<\/a>/', '$1', $markdown);
        $markdown = preg_replace('/<a href="(.*?)">(.*?)<\/a>/', '[$2]($1)', $markdown);

        $lines     = explode("\n", $markdown);
        $inList    = false;
        $listType  = '';
        $listCount = 0;
        $result    = [];

        foreach ($lines as $line) {
            if (trim($line) === '' && ! $inList) {
                $result[] = '';
                continue;
            }
            if (strpos($line, '<ul>') !== false) {
                $inList   = true;
                $listType = 'ul';
                continue;
            }
            if (strpos($line, '<ol>') !== false) {
                $inList    = true;
                $listType  = 'ol';
                $listCount = 0;
                continue;
            }
            if (strpos($line, '</ul>') !== false || strpos($line, '</ol>') !== false) {
                $inList   = false;
                $listType = '';
                $result[] = '';
                continue;
            }

            if (preg_match('/<li>(.*?)<\/li>/', $line, $matches)) {
                $content = $matches[1];
                if ($listType === 'ul') {
                    $result[] = '- ' . $content;
                } elseif ($listType === 'ol') {
                    $listCount++;
                    $result[] = $listCount . '. ' . $content;
                }
                continue;
            }

            if (trim($line) !== '') {
                $result[] = trim($line);
            }
        }

        $markdown = implode("\n", $result);
        $markdown = html_entity_decode(wp_strip_all_tags($markdown), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $markdown = preg_replace('/\n{3,}/', "\n\n", $markdown);
        $markdown = preg_replace('/^(#{1,3}\s.*?)$/m', "\n$1\n", $markdown);
        $markdown = preg_replace_callback(
            '/' . self::TOKEN_OPEN . '(\d+)' . self::TOKEN_CLOSE . '/u',
            static fn (array $m): string => $codes[(int) $m[1]] ?? '',
            $markdown
        );

        return trim($markdown);
    }

    /**
     * Shared open/close logic for ordered and unordered list items.
     *
     * @param 'ol'|'ul' $type
     * @param list<array{type: string, indent: int}> $listStack
     * @return array{0: string, 1: list<array{type: string, indent: int}>, 2: int}
     */
    private static function handleListItem(
        string $type,
        string $content,
        int $indent,
        string $output,
        array $listStack,
        int $currentIndent
    ): array {
        $openTag = $type === 'ol' ? "<ol>\n" : "<ul>\n";

        if (empty($listStack) || $indent > $currentIndent) {
            $output     .= $openTag;
            $listStack[] = ['type' => $type, 'indent' => $indent];
        } else {
            if ($indent < $currentIndent) {
                while (! empty($listStack) && end($listStack)['indent'] > $indent) {
                    $list    = array_pop($listStack);
                    $output .= $list['type'] === 'ul' ? "</ul>\n" : "</ol>\n";
                }
            }

            // Switching between "-" and "1." at the same level starts a new list.
            if (! empty($listStack) && end($listStack)['type'] !== $type) {
                $list        = array_pop($listStack);
                $output     .= $list['type'] === 'ul' ? "</ul>\n" : "</ol>\n";
                $output     .= $openTag;
                $listStack[] = ['type' => $type, 'indent' => $indent];
            }
        }

        $output .= '<li>' . $content . "</li>\n";
        return [$output, $listStack, $indent];
    }
}
