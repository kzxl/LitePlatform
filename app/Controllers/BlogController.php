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
 * SEO-optimized Blog Controller rendering published articles with Article + BreadcrumbList JSON-LD
 * and sanitized Rich Text content.
 */
final class BlogController
{
    public function __construct(private readonly Container $container)
    {
    }

    public function index(Request $request, Response $response): Response
    {
        /** @var PDO $pdo */
        $pdo = $this->container->get(PDO::class);
        /** @var ViewEngine $view */
        $view = $this->container->get(ViewEngine::class);

        $page = max(1, (int)($request->getQueryParams()['page'] ?? 1));
        $perPage = 12;
        $offset = ($page - 1) * $perPage;

        $stmt = $pdo->prepare(
            "SELECT id, title, slug, excerpt, featured_image, published_at
             FROM posts
             WHERE status = 'published'
             ORDER BY published_at DESC, id DESC
             LIMIT :limit OFFSET :offset"
        );
        $stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
        $posts = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $appUrl = rtrim((string)($this->container->get('config')['url'] ?? 'http://localhost:8000'), '/');
        $seo = $this->container->has(SeoMeta::class) ? $this->container->get(SeoMeta::class) : new SeoMeta();
        $seo = $seo
            ->withTitle('Blog')
            ->withCanonical($appUrl . '/blog')
            ->addSchema(SchemaBuilder::breadcrumb([
                ['name' => 'Home', 'url' => $appUrl . '/'],
                ['name' => 'Blog', 'url' => $appUrl . '/blog'],
            ]));

        return $view->renderResponse($response, 'blog.index', [
            'seo'   => $seo,
            'posts' => $posts,
            'page'  => $page,
        ]);
    }

    public function show(Request $request, Response $response, array $args): Response
    {
        $slug = trim((string)($args['slug'] ?? ''));
        if ($slug === '' || !preg_match('/^[a-z0-9\-]+$/', $slug)) {
            throw new HttpNotFoundException($request);
        }

        /** @var PDO $pdo */
        $pdo = $this->container->get(PDO::class);
        $stmt = $pdo->prepare(
            "SELECT * FROM posts WHERE slug = :slug AND status = 'published' LIMIT 1"
        );
        $stmt->execute([':slug' => $slug]);
        $post = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!is_array($post)) {
            throw new HttpNotFoundException($request);
        }

        $appUrl = rtrim((string)($this->container->get('config')['url'] ?? 'http://localhost:8000'), '/');
        $articleUrl = $appUrl . '/blog/' . $post['slug'];
        $metaTitle = !empty($post['meta_title']) ? (string)$post['meta_title'] : (string)$post['title'];
        $metaDesc = !empty($post['meta_description'])
            ? (string)$post['meta_description']
            : (string)($post['excerpt'] ?? $post['content']);

        $seo = $this->container->has(SeoMeta::class) ? $this->container->get(SeoMeta::class) : new SeoMeta();
        $seo = $seo
            ->withTitle($metaTitle)
            ->withDescription($metaDesc)
            ->withCanonical($articleUrl)
            ->withOpenGraph('article', !empty($post['featured_image']) ? (string)$post['featured_image'] : null)
            ->addSchema(SchemaBuilder::breadcrumb([
                ['name' => 'Home', 'url' => $appUrl . '/'],
                ['name' => 'Blog', 'url' => $appUrl . '/blog'],
                ['name' => (string)$post['title'], 'url' => $articleUrl],
            ]))
            ->addSchema(SchemaBuilder::article(
                headline: (string)$post['title'],
                url: $articleUrl,
                datePublished: (string)($post['published_at'] ?? $post['created_at'] ?? date('c')),
                dateModified: (string)($post['updated_at'] ?? $post['published_at'] ?? date('c')),
                image: !empty($post['featured_image']) ? (string)$post['featured_image'] : null,
                description: $metaDesc
            ));

        /** @var ViewEngine $view */
        $view = $this->container->get(ViewEngine::class);

        return $view->renderResponse($response, 'blog.show', [
            'seo'  => $seo,
            'post' => $post,
        ]);
    }
}
