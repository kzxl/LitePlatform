<?php

declare(strict_types=1);

namespace Lite\Framework\Seo;

/**
 * Fluent SEO metadata container rendering escaped <head> tags, OpenGraph,
 * Twitter Card, canonical/pagination links, and JSON-LD structured data.
 */
final class SeoMeta
{
    /** @var list<array<string, mixed>> */
    private array $schemas = [];

    /** @var array<string, string> Map of hreflang => absolute URL */
    private array $alternates = [];

    public function __construct(
        private string $title = 'LitePlatform',
        private string $description = '',
        private string $siteName = 'LitePlatform',
        private ?string $canonical = null,
        private string $robots = 'index, follow',
        private string $ogType = 'website',
        private ?string $ogImage = null,
        private string $twitterCard = 'summary_large_image',
        private ?string $twitterHandle = null,
        private string $locale = 'vi_VN',
        private ?string $prevUrl = null,
        private ?string $nextUrl = null
    ) {
    }

    public function withTitle(string $title, bool $appendSiteName = true): self
    {
        $clone = clone $this;
        $trimmed = trim($title);
        if ($appendSiteName && $this->siteName !== '' && !str_contains($trimmed, $this->siteName)) {
            $clone->title = "{$trimmed} | {$this->siteName}";
        } else {
            $clone->title = $trimmed;
        }

        return $clone;
    }

    public function withDescription(string $description): self
    {
        $clone = clone $this;
        $clean = trim((string)preg_replace('/\s+/', ' ', strip_tags($description)));
        $clone->description = mb_strlen($clean) > 160 ? rtrim(mb_substr($clean, 0, 157)) . '...' : $clean;

        return $clone;
    }

    public function withCanonical(?string $url): self
    {
        $clone = clone $this;
        $clone->canonical = $url !== null ? trim($url) : null;

        return $clone;
    }

    public function withRobots(string $robots): self
    {
        $clone = clone $this;
        $clone->robots = trim($robots);

        return $clone;
    }

    public function noIndex(): self
    {
        return $this->withRobots('noindex, nofollow');
    }

    public function withOpenGraph(string $type = 'website', ?string $image = null): self
    {
        $clone = clone $this;
        $clone->ogType = $type;
        if ($image !== null) {
            $clone->ogImage = $image;
        }

        return $clone;
    }

    public function withPagination(?string $prevUrl, ?string $nextUrl): self
    {
        $clone = clone $this;
        $clone->prevUrl = $prevUrl;
        $clone->nextUrl = $nextUrl;

        return $clone;
    }

    public function withLocale(string $ogLocale): self
    {
        $clone = clone $this;
        $clone->locale = trim($ogLocale);

        return $clone;
    }

    /**
     * Set hreflang alternate links (e.g. ['vi' => 'https://example.com/?lang=vi', 'en' => '...', 'x-default' => '...']).
     *
     * @param array<string, string> $alternates
     */
    public function withAlternates(array $alternates): self
    {
        $clone = clone $this;
        $clone->alternates = $alternates;

        return $clone;
    }

    /**
     * @param array<string, mixed> $schema
     */
    public function addSchema(array $schema): self
    {
        $clone = clone $this;
        $clone->schemas[] = $schema;

        return $clone;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function getDescription(): string
    {
        return $this->description;
    }

    public function getCanonical(): ?string
    {
        return $this->canonical;
    }

    public function getRobots(): string
    {
        return $this->robots;
    }

    /**
     * Render all escaped SEO <head> tags and JSON-LD scripts.
     */
    public function renderHeadTags(): string
    {
        $lines = [];
        $lines[] = '<title>' . $this->e($this->title) . '</title>';

        if ($this->description !== '') {
            $lines[] = '<meta name="description" content="' . $this->e($this->description) . '">';
        }

        if ($this->robots !== '') {
            $lines[] = '<meta name="robots" content="' . $this->e($this->robots) . '">';
        }

        if ($this->canonical !== null && $this->canonical !== '') {
            $lines[] = '<link rel="canonical" href="' . $this->e($this->canonical) . '">';
        }

        foreach ($this->alternates as $hreflang => $href) {
            if ($hreflang !== '' && $href !== '') {
                $lines[] = '<link rel="alternate" hreflang="' . $this->e($hreflang) . '" href="' . $this->e($href) . '">';
            }
        }

        if ($this->prevUrl !== null && $this->prevUrl !== '') {
            $lines[] = '<link rel="prev" href="' . $this->e($this->prevUrl) . '">';
        }

        if ($this->nextUrl !== null && $this->nextUrl !== '') {
            $lines[] = '<link rel="next" href="' . $this->e($this->nextUrl) . '">';
        }

        // OpenGraph tags
        $lines[] = '<meta property="og:site_name" content="' . $this->e($this->siteName) . '">';
        $lines[] = '<meta property="og:type" content="' . $this->e($this->ogType) . '">';
        $lines[] = '<meta property="og:title" content="' . $this->e($this->title) . '">';
        $lines[] = '<meta property="og:locale" content="' . $this->e($this->locale) . '">';

        if ($this->description !== '') {
            $lines[] = '<meta property="og:description" content="' . $this->e($this->description) . '">';
        }
        if ($this->canonical !== null && $this->canonical !== '') {
            $lines[] = '<meta property="og:url" content="' . $this->e($this->canonical) . '">';
        }
        if ($this->ogImage !== null && $this->ogImage !== '') {
            $lines[] = '<meta property="og:image" content="' . $this->e($this->ogImage) . '">';
        }

        // Twitter Card tags
        $lines[] = '<meta name="twitter:card" content="' . $this->e($this->twitterCard) . '">';
        $lines[] = '<meta name="twitter:title" content="' . $this->e($this->title) . '">';
        if ($this->description !== '') {
            $lines[] = '<meta name="twitter:description" content="' . $this->e($this->description) . '">';
        }
        if ($this->ogImage !== null && $this->ogImage !== '') {
            $lines[] = '<meta name="twitter:image" content="' . $this->e($this->ogImage) . '">';
        }
        if ($this->twitterHandle !== null && $this->twitterHandle !== '') {
            $lines[] = '<meta name="twitter:site" content="' . $this->e($this->twitterHandle) . '">';
        }

        // JSON-LD structured data scripts
        foreach ($this->schemas as $schema) {
            $json = json_encode(
                $schema,
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP
            );
            if ($json !== false) {
                $lines[] = '<script type="application/ld+json">' . $json . '</script>';
            }
        }

        return implode("\n    ", $lines);
    }

    private function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
