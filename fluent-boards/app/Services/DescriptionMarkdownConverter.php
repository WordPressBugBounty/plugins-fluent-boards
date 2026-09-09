<?php

namespace FluentBoards\App\Services;

/**
 * Dependency-free HTML → Markdown converter for the one-time description
 * migration (see docs/plan/description-markdown-migration.md).
 *
 * Legacy task/board descriptions were authored with the WP editor and stored as
 * HTML; the Milkdown editor stores markdown. This converts the common subset of
 * tags the old editor emitted (headings, emphasis, links, images, lists, quotes,
 * code, rules). It is intentionally self-contained rather than pulling a composer
 * dependency into a distributed WP plugin. Anything it converts imperfectly is
 * recoverable because the migration keeps the original HTML backup in meta.
 */
class DescriptionMarkdownConverter
{
    /**
     * Cheap heuristic mirroring the frontend `looksLikeHtml` helper: does the
     * string contain markup the legacy editor would have produced?
     */
    public static function looksLikeHtml($str): bool
    {
        if (!is_string($str) || $str === '') {
            return false;
        }

        return (bool) preg_match(
            '/<(\/?)(p|div|br|span|ul|ol|li|h[1-6]|img|a|table|pre|blockquote|strong|em|code)\b[^>]*>/i',
            $str
        );
    }

    /**
     * Convert an HTML description to markdown. Plain text / already-markdown
     * input is returned trimmed and unchanged.
     */
    public static function convert($html): string
    {
        if (!is_string($html) || trim($html) === '') {
            return '';
        }

        if (!self::looksLikeHtml($html)) {
            return trim($html);
        }

        $dom = new \DOMDocument();
        $previous = libxml_use_internal_errors(true);

        // Force UTF-8 and wrap in a known root so we can walk a single subtree.
        $loaded = $dom->loadHTML(
            '<?xml encoding="UTF-8"?><div id="__fbs_root__">' . $html . '</div>',
            LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD
        );

        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if (!$loaded) {
            // Fall back to a tag strip rather than losing the content entirely.
            return trim(wp_strip_all_tags($html));
        }

        $root = $dom->getElementById('__fbs_root__');
        $markdown = $root ? self::renderChildren($root) : '';

        // Normalise trailing whitespace without removing Markdown hard breaks.
        $markdown = preg_replace_callback('/[ \t]+\n/', function ($matches) {
            return substr($matches[0], -3) === "  \n" ? "  \n" : "\n";
        }, $markdown);
        $markdown = preg_replace("/\n{3,}/", "\n\n", $markdown);

        return trim($markdown);
    }

    /**
     * Normalize mixed legacy HTML / markdown descriptions to markdown without
     * letting converter failures break API or MCP responses.
     */
    public static function normalize($description): string
    {
        if (!is_string($description) || trim($description) === '') {
            return '';
        }

        if (!self::looksLikeHtml($description)) {
            // Milkdown already serializes new descriptions as Markdown. Rewriting
            // its line breaks here changes code blocks and other pasted content
            // between the live editor and the next reload.
            return $description;
        }

        try {
            return self::convert($description);
        } catch (\Throwable $e) {
            return self::fallbackToPlainText($description);
        }
    }

    private static function fallbackToPlainText($html): string
    {
        $html = preg_replace('/<(br|\/p|\/div|\/li|\/h[1-6]|\/blockquote|\/tr)\b[^>]*>/i', "\n", $html);
        $html = preg_replace('/<(p|div|li|h[1-6]|blockquote|tr)\b[^>]*>/i', "\n", $html);
        $text = function_exists('wp_strip_all_tags')
            ? wp_strip_all_tags($html)
            : strip_tags($html);

        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace("/[ \t]+\n/", "\n", $text);
        $text = preg_replace("/\n{3,}/", "\n\n", $text);

        return trim($text);
    }

    private static function renderChildren(\DOMNode $node): string
    {
        $out = '';
        foreach ($node->childNodes as $child) {
            $out .= self::renderNode($child);
        }
        return $out;
    }

    private static function renderNode(\DOMNode $node): string
    {
        if ($node->nodeType === XML_TEXT_NODE) {
            // Collapse runs of whitespace (HTML is whitespace-insensitive).
            return preg_replace('/\s+/', ' ', $node->nodeValue);
        }

        if ($node->nodeType !== XML_ELEMENT_NODE) {
            return '';
        }

        $tag = strtolower($node->nodeName);

        switch ($tag) {
            case 'h1': return "\n\n# "     . trim(self::renderChildren($node)) . "\n\n";
            case 'h2': return "\n\n## "    . trim(self::renderChildren($node)) . "\n\n";
            case 'h3': return "\n\n### "   . trim(self::renderChildren($node)) . "\n\n";
            case 'h4': return "\n\n#### "  . trim(self::renderChildren($node)) . "\n\n";
            case 'h5': return "\n\n##### " . trim(self::renderChildren($node)) . "\n\n";
            case 'h6': return "\n\n###### ". trim(self::renderChildren($node)) . "\n\n";

            case 'p':
            case 'div':
                $inner = trim(self::renderChildren($node));
                return $inner === '' ? '' : "\n\n" . $inner . "\n\n";

            case 'br':
                return "  \n";

            case 'strong':
            case 'b':
                return '**' . self::renderChildren($node) . '**';

            case 'em':
            case 'i':
                return '*' . self::renderChildren($node) . '*';

            case 'del':
            case 's':
            case 'strike':
                return '~~' . self::renderChildren($node) . '~~';

            case 'code':
                // Inline code (code inside <pre> is handled by the 'pre' branch).
                if ($node->parentNode && strtolower($node->parentNode->nodeName) === 'pre') {
                    return self::renderChildren($node);
                }
                return '`' . self::textContent($node) . '`';

            case 'pre':
                return "\n\n```\n" . rtrim(self::textContent($node)) . "\n```\n\n";

            case 'blockquote':
                $inner = trim(self::renderChildren($node));
                $quoted = preg_replace('/^/m', '> ', $inner);
                return "\n\n" . $quoted . "\n\n";

            case 'hr':
                return "\n\n---\n\n";

            case 'a':
                $href = $node->getAttribute('href');
                $text = self::renderChildren($node);
                if ($href === '') {
                    return $text;
                }
                return '[' . $text . '](' . $href . ')';

            case 'img':
                $src = $node->getAttribute('src');
                $alt = $node->getAttribute('alt');
                return $src === '' ? '' : '![' . $alt . '](' . $src . ')';

            case 'ul':
            case 'ol':
                return "\n\n" . self::renderList($node, $tag === 'ol') . "\n";

            case 'li':
                // Handled by renderList; render inline if reached directly.
                return self::renderChildren($node);

            default:
                return self::renderChildren($node);
        }
    }

    private static function renderList(\DOMNode $node, bool $ordered): string
    {
        $out = '';
        $index = 1;
        foreach ($node->childNodes as $child) {
            if ($child->nodeType !== XML_ELEMENT_NODE || strtolower($child->nodeName) !== 'li') {
                continue;
            }

            $marker = $ordered ? ($index . '. ') : '- ';
            $content = trim(self::renderChildren($child));
            // Indent wrapped/nested lines under the marker.
            $content = preg_replace("/\n/", "\n" . str_repeat(' ', strlen($marker)), $content);
            $out .= $marker . $content . "\n";
            $index++;
        }
        return $out;
    }

    private static function textContent(\DOMNode $node): string
    {
        return $node->textContent;
    }
}
