<?php

declare(strict_types=1);

namespace Lite\Framework\Security;

use DOMDocument;
use DOMElement;
use DOMNode;

/**
 * Pure PHP DOMDocument allowlist HTML sanitizer for Rich Text content (blog posts, product descriptions).
 * Prevents Stored XSS when rendering formatted HTML via @sanitize($html) or {!! $sanitize($html) !!}.
 */
final class HtmlSanitizer
{
    /** @var array<string, list<string>> */
    private const DEFAULT_ALLOWED_TAGS = [
        'p'          => ['class'],
        'br'         => [],
        'hr'         => [],
        'strong'     => [],
        'b'          => [],
        'em'         => [],
        'i'          => [],
        'u'          => [],
        's'          => [],
        'sub'        => [],
        'sup'        => [],
        'blockquote' => ['class'],
        'pre'        => ['class'],
        'code'       => ['class'],
        'h1'         => ['id', 'class'],
        'h2'         => ['id', 'class'],
        'h3'         => ['id', 'class'],
        'h4'         => ['id', 'class'],
        'h5'         => ['id', 'class'],
        'h6'         => ['id', 'class'],
        'ul'         => ['class'],
        'ol'         => ['class', 'start'],
        'li'         => ['class'],
        'a'          => ['href', 'title', 'target', 'rel', 'class'],
        'img'        => ['src', 'alt', 'title', 'width', 'height', 'loading', 'decoding', 'class'],
        'figure'     => ['class'],
        'figcaption' => ['class'],
        'table'      => ['class'],
        'thead'      => [],
        'tbody'      => [],
        'tfoot'      => [],
        'tr'         => [],
        'th'         => ['colspan', 'rowspan', 'scope'],
        'td'         => ['colspan', 'rowspan'],
        'span'       => ['class'],
        'div'        => ['class'],
    ];

    /** @var list<string> Tags whose entire subtree (including text content) must be removed */
    private const DROP_WITH_CONTENT = [
        'script', 'style', 'iframe', 'object', 'embed', 'applet', 'svg', 'math', 'noscript', 'template', 'form',
    ];

    /** @var array<string, list<string>> */
    private array $allowedTags;

    /**
     * @param array<string, list<string>>|null $allowedTags
     */
    public function __construct(?array $allowedTags = null)
    {
        $this->allowedTags = $allowedTags ?? self::DEFAULT_ALLOWED_TAGS;
    }

    public function sanitize(string $html): string
    {
        if (trim($html) === '') {
            return '';
        }

        $prevUseErrors = libxml_use_internal_errors(true);
        $dom = new DOMDocument('1.0', 'UTF-8');

        // Wrap in UTF-8 root container to preserve multibyte Vietnamese characters cleanly
        $wrapped = '<?xml encoding="UTF-8"><div id="__lite_root__">' . $html . '</div>';
        $dom->loadHTML($wrapped, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($prevUseErrors);

        $root = $dom->getElementById('__lite_root__');
        if ($root === null) {
            return '';
        }

        $this->cleanNode($root);

        $output = '';
        foreach ($root->childNodes as $child) {
            $output .= $dom->saveHTML($child);
        }

        return trim($output);
    }

    private function cleanNode(DOMNode $parent): void
    {
        $children = [];
        foreach ($parent->childNodes as $child) {
            $children[] = $child;
        }

        foreach ($children as $node) {
            if ($node->nodeType === XML_COMMENT_NODE) {
                $parent->removeChild($node);
                continue;
            }

            if (!$node instanceof DOMElement) {
                continue;
            }

            $tag = strtolower($node->tagName);

            if (in_array($tag, self::DROP_WITH_CONTENT, true)) {
                $parent->removeChild($node);
                continue;
            }

            // Recursively sanitize children before potentially unwrapping this element
            $this->cleanNode($node);

            if (!isset($this->allowedTags[$tag])) {
                // Unwrap disallowed non-dangerous container tags while preserving safe inner text/elements
                while ($node->firstChild !== null) {
                    $parent->insertBefore($node->firstChild, $node);
                }
                $parent->removeChild($node);
                continue;
            }

            $this->cleanAttributes($node, $this->allowedTags[$tag]);

            if ($tag === 'a' && strtolower($node->getAttribute('target')) === '_blank') {
                $node->setAttribute('rel', 'noopener noreferrer');
            }
        }
    }

    /**
     * @param list<string> $allowedAttrs
     */
    private function cleanAttributes(DOMElement $element, array $allowedAttrs): void
    {
        $toRemove = [];
        foreach ($element->attributes as $attr) {
            $name = strtolower($attr->name);
            if (str_starts_with($name, 'on') || !in_array($name, $allowedAttrs, true)) {
                $toRemove[] = $attr->name;
                continue;
            }

            if ($name === 'href' || $name === 'src') {
                if (!$this->isSafeUrl($attr->value, $name === 'src')) {
                    $toRemove[] = $attr->name;
                }
            }
        }

        foreach ($toRemove as $attrName) {
            $element->removeAttribute($attrName);
        }
    }

    private function isSafeUrl(string $url, bool $isMediaSrc): bool
    {
        // Strip control characters and whitespace tricks (e.g. "java\tscript:")
        $normalized = preg_replace('/[\x00-\x20\x7F]+/', '', trim($url)) ?? '';
        if ($normalized === '') {
            return false;
        }

        // Allow safe relative URLs and anchors
        if (str_starts_with($normalized, '/') && !str_starts_with($normalized, '//')) {
            return true;
        }
        if (!$isMediaSrc && str_starts_with($normalized, '#')) {
            return true;
        }

        $scheme = strtolower((string)parse_url($normalized, PHP_URL_SCHEME));
        if ($scheme === '') {
            return !str_contains($normalized, ':');
        }

        $allowedSchemes = $isMediaSrc ? ['http', 'https'] : ['http', 'https', 'mailto', 'tel'];
        return in_array($scheme, $allowedSchemes, true);
    }
}
