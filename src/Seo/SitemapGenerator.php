<?php

declare(strict_types=1);

namespace App\Seo;

/**
 * Generates standard XML sitemaps from static paths and dynamic URL providers,
 * with optional LiteCache backing for low-overhead shared hosting delivery.
 */
final class SitemapGenerator
{
    /** @var list<array{loc: string, lastmod?: string, changefreq?: string, priority?: string}> */
    private array $staticUrls = [];

    /** @var list<callable(): list<array{loc: string, lastmod?: string, changefreq?: string, priority?: string}>> */
    private array $providers = [];

    public function __construct(
        private readonly string $baseUrl,
        private readonly ?\LiteCache\CacheInterface $cache = null,
        private readonly int $cacheTtl = 3600
    ) {
    }

    public function addUrl(
        string $pathOrUrl,
        ?string $lastmod = null,
        string $changefreq = 'weekly',
        string $priority = '0.8'
    ): self {
        $entry = [
            'loc'        => $this->normalizeUrl($pathOrUrl),
            'changefreq' => $changefreq,
            'priority'   => $priority,
        ];
        if ($lastmod !== null && $lastmod !== '') {
            $entry['lastmod'] = $lastmod;
        }

        $this->staticUrls[] = $entry;

        return $this;
    }

    /**
     * Register a lazy provider callback returning URL entries (e.g., from MySQL/LiteORM).
     *
     * @param callable(): list<array{loc: string, lastmod?: string, changefreq?: string, priority?: string}> $provider
     */
    public function addProvider(callable $provider): self
    {
        $this->providers[] = $provider;

        return $this;
    }

    public function renderXml(bool $useCache = true): string
    {
        if ($useCache && $this->cache !== null) {
            return (string)$this->cache->tags(['seo', 'sitemap'])->remember(
                'seo_sitemap_xml',
                $this->cacheTtl,
                fn(): string => $this->buildXml()
            );
        }

        return $this->buildXml();
    }

    public function invalidateCache(): void
    {
        if ($this->cache !== null) {
            $this->cache->tags(['sitemap'])->flushTags();
        }
    }

    private function buildXml(): string
    {
        $entries = $this->staticUrls;

        foreach ($this->providers as $provider) {
            foreach ($provider() as $entry) {
                $entry['loc'] = $this->normalizeUrl($entry['loc']);
                $entries[] = $entry;
            }
        }

        $xml = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n";
        $xml .= "<urlset xmlns=\"http://www.sitemaps.org/schemas/sitemap/0.9\">\n";

        $seen = [];
        foreach ($entries as $entry) {
            $loc = $entry['loc'];
            if (isset($seen[$loc])) {
                continue;
            }
            $seen[$loc] = true;

            $xml .= "  <url>\n";
            $xml .= '    <loc>' . htmlspecialchars($loc, ENT_XML1 | ENT_QUOTES, 'UTF-8') . "</loc>\n";
            if (!empty($entry['lastmod'])) {
                $xml .= '    <lastmod>' . htmlspecialchars((string)$entry['lastmod'], ENT_XML1 | ENT_QUOTES, 'UTF-8') . "</lastmod>\n";
            }
            if (!empty($entry['changefreq'])) {
                $xml .= '    <changefreq>' . htmlspecialchars((string)$entry['changefreq'], ENT_XML1 | ENT_QUOTES, 'UTF-8') . "</changefreq>\n";
            }
            if (!empty($entry['priority'])) {
                $xml .= '    <priority>' . htmlspecialchars((string)$entry['priority'], ENT_XML1 | ENT_QUOTES, 'UTF-8') . "</priority>\n";
            }
            $xml .= "  </url>\n";
        }

        $xml .= "</urlset>\n";

        return $xml;
    }

    private function normalizeUrl(string $pathOrUrl): string
    {
        if (str_starts_with($pathOrUrl, 'http://') || str_starts_with($pathOrUrl, 'https://')) {
            return $pathOrUrl;
        }

        return rtrim($this->baseUrl, '/') . '/' . ltrim($pathOrUrl, '/');
    }
}
