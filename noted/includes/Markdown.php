<?php

declare(strict_types=1);

namespace Noted;

/**
 * Lightweight Markdown <-> HTML converter for note bodies.
 *
 * Supports headings, bold/italic, links, and (nested) ordered and unordered
 * lists. Not a full Markdown spec — just enough for the note UX.
 */
final class Markdown
{
    /**
     * Convert a Markdown-flavoured string to safe HTML.
     *
     * The final output is run through {@see wp_kses_post()}, so even a
     * regex that accidentally lets a stray tag through is contained at
     * the boundary.
     */
    public static function toHtml(string $text): string
    {
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        $text = trim($text);

        $inlinePatterns = [
            '/\#{6}\s?(.*)/'                          => '<h6>$1</h6>',
            '/\#{5}\s?(.*)/'                          => '<h5>$1</h5>',
            '/\#{4}\s?(.*)/'                          => '<h4>$1</h4>',
            '/\#{3}\s?(.*)/'                          => '<h3>$1</h3>',
            '/\#{2}\s?(.*)/'                          => '<h2>$1</h2>',
            '/\#{1}\s?(.*)/'                          => '<h1>$1</h1>',
            '/\*\*(.*?)\*\*|__(.*?)__/'               => '<strong>$1$2</strong>',
            '/\*(.*?)\*|_(.*?)_/'                     => '<em>$1$2</em>',
            '/<(https?:\/\/[^\s<]+)>/'                => '<a href="$1">$1</a>',
            '/\[(.*?)\]\((.*?)\)/'                    => '<a href="$2">$1</a>',
            '/(^|[\s>])(https?:\/\/[^\s<]+)(?=\s|$)/' => '$1<a href="$2">$2</a>',
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
        return wp_kses_post(wpautop(trim($output)));
    }

    /**
     * Convert plugin-generated HTML back to Markdown.
     *
     * Best-effort — used to round-trip note bodies during edits.
     */
    public static function toMarkdown(string $html): string
    {
        $markdown = $html;

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
        $markdown = preg_replace('/<em>(.*?)<\/em>/', '_$1_', $markdown);

        $markdown = preg_replace('/<a href="(.*?)">\1<\/a>/', '<$1>', $markdown);
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
        $markdown = wp_strip_all_tags($markdown);
        $markdown = preg_replace('/\n{3,}/', "\n\n", $markdown);
        $markdown = preg_replace('/^(#{1,3}\s.*?)$/m', "\n$1\n", $markdown);

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
        } elseif ($indent < $currentIndent) {
            while (! empty($listStack) && end($listStack)['indent'] > $indent) {
                $list    = array_pop($listStack);
                $output .= $list['type'] === 'ul' ? "</ul>\n" : "</ol>\n";
            }
        }

        $output .= '<li>' . $content . "</li>\n";
        return [$output, $listStack, $indent];
    }
}
