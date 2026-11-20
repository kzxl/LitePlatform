<?php

declare(strict_types=1);

namespace App\Controllers;

use DI\Container;
use Lite\Framework\Cms\SiteSettings;
use Lite\Framework\I18n\Translator;
use Lite\Framework\Seo\SeoMeta;
use LiteView\ViewEngine;
use PDO;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Starter Website Frontpage Controller.
 * Renders real website content (SiteSettings, latest published Blog Posts, active Products)
 * while preserving HTMX live metrics fragment support (/fragment/metrics).
 */
class HomeController
{
    public function __construct(private Container $container)
    {
    }

    /**
     * Render the Starter Website homepage.
     */
    public function index(Request $request, Response $response): Response
    {
        if (!$this->container->has(ViewEngine::class)) {
            $response->getBody()->write('<h1>LitePlatform</h1><p>ViewEngine is disabled or not installed.</p>');
            return $response->withHeader('Content-Type', 'text/html');
        }

        /** @var ViewEngine $view */
        $view = $this->container->get(ViewEngine::class);
        $data = $this->getHomepageData();

        $html = $view->render('home', $data);
        $response->getBody()->write($html);
        return $response->withHeader('Content-Type', 'text/html; charset=utf-8');
    }

    /**
     * Direct HTMX fragment endpoint for live runtime metrics.
     */
    public function metrics(Request $request, Response $response): Response
    {
        $html = sprintf(
            '<div class="card"><strong>PHP %s</strong> &middot; RAM: %s MB &middot; %s</div>',
            htmlspecialchars(PHP_VERSION, ENT_QUOTES, 'UTF-8'),
            round(memory_get_usage(true) / 1024 / 1024, 2),
            date('Y-m-d H:i:s')
        );
        $response->getBody()->write($html);
        return $response->withHeader('Content-Type', 'text/html; charset=utf-8');
    }

    /**
     * Gather dynamic site settings, latest published posts, and featured products.
     *
     * @return array<string, mixed>
     */
    private function getHomepageData(): array
    {
        $translator = $this->container->has(Translator::class)
            ? $this->container->get(Translator::class)
            : null;
        $settings = $this->container->has(SiteSettings::class)
            ? $this->container->get(SiteSettings::class)
            : null;

        $config = (array)$this->container->get('config');
        $siteName = $settings ? $settings->get('site_name', (string)($config['name'] ?? 'LitePlatform')) : (string)($config['name'] ?? 'LitePlatform');
        $tagline = $settings ? $settings->get('tagline', '') : '';

        $pageTitle = $translator
            ? $translator->get('home.page_title')
            : $siteName;

        $seo = null;
        if ($this->container->has(SeoMeta::class)) {
            /** @var SeoMeta $baseSeo */
            $baseSeo = $this->container->get(SeoMeta::class);
            $seo = $baseSeo->withTitle($pageTitle, appendSiteName: false);
            if ($tagline !== '') {
                $seo = $seo->withDescription($tagline);
            }

            if ($translator !== null) {
                $appUrl = rtrim((string)($config['url'] ?? 'http://localhost:8000'), '/');
                $alternates = ['x-default' => $appUrl . '/'];
                foreach ($translator->getSupportedLocales() as $loc) {
                    $alternates[$loc] = $appUrl . '/?lang=' . $loc;
                }
                $seo = $seo
                    ->withLocale($translator->getOgLocale())
                    ->withAlternates($alternates);
            }
        }

        $latestPosts = [];
        $featuredProducts = [];

        if ($this->container->has(PDO::class)) {
            /** @var PDO $pdo */
            $pdo = $this->container->get(PDO::class);

            try {
                $stmt = $pdo->query(
                    "SELECT id, title, slug, excerpt, featured_image, published_at
                     FROM posts WHERE status = 'published'
                     ORDER BY published_at DESC, id DESC LIMIT 3"
                );
                $latestPosts = $stmt ? ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
            } catch (\Throwable) {
                $latestPosts = [];
            }

            try {
                $stmt = $pdo->query(
                    "SELECT id, name, price, stock FROM products WHERE is_active = 1 ORDER BY id DESC LIMIT 4"
                );
                $featuredProducts = $stmt ? ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
            } catch (\Throwable) {
                $featuredProducts = [];
            }
        }

        return [
            'title'            => $pageTitle,
            'siteName'         => $siteName,
            'tagline'          => $tagline,
            'seo'              => $seo,
            'latestPosts'      => $latestPosts,
            'featuredProducts' => $featuredProducts,
        ];
    }
}
