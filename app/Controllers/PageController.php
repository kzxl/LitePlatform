<?php

declare(strict_types=1);

namespace App\Controllers;

use DI\Container;
use Lite\Framework\Seo\SchemaBuilder;
use Lite\Framework\Seo\SeoMeta;
use LiteView\ViewEngine;
use PDO;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Exception\HttpNotFoundException;

/**
 * Dynamic WordPress-style Page Controller serving published Pages at /{slug}
 * (e.g. /gioi-thieu, /dich-vu, /bang-gia, /chinh-sach).
 */
final class PageController
{
    public function __construct(private readonly Container $container)
    {
    }

    public function show(Request $request, Response $response, array $args): Response
    {
        $slug = trim((string)($args['slug'] ?? ''));
        if ($slug === '' || !preg_match('/^[a-z0-9\-]+$/', $slug)) {
            throw new HttpNotFoundException($request);
        }

        /** @var PDO $pdo */
        $pdo = $this->container->get(PDO::class);
        $stmt = $pdo->prepare("SELECT * FROM pages WHERE slug = :slug AND status = 'published' LIMIT 1");
        $stmt->execute([':slug' => $slug]);
        $page = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!is_array($page)) {
            throw new HttpNotFoundException($request);
        }

        $appUrl = rtrim((string)($this->container->get('config')['url'] ?? 'http://localhost:8000'), '/');
        $pageUrl = $appUrl . '/' . $page['slug'];
        $metaTitle = !empty($page['meta_title']) ? (string)$page['meta_title'] : (string)$page['title'];
        $metaDesc = !empty($page['meta_description']) ? (string)$page['meta_description'] : (string)$page['content'];

        $seo = $this->container->has(SeoMeta::class) ? $this->container->get(SeoMeta::class) : new SeoMeta();
        $seo = $seo
            ->withTitle($metaTitle)
            ->withDescription($metaDesc)
            ->withCanonical($pageUrl)
            ->withOpenGraph('website', !empty($page['featured_image']) ? (string)$page['featured_image'] : null)
            ->addSchema(SchemaBuilder::breadcrumb([
                ['name' => 'Home', 'url' => $appUrl . '/'],
                ['name' => (string)$page['title'], 'url' => $pageUrl],
            ]));

        /** @var ViewEngine $view */
        $view = $this->container->get(ViewEngine::class);

        return $view->renderResponse($response, 'page', [
            'seo'  => $seo,
            'page' => $page,
        ]);
    }
}
