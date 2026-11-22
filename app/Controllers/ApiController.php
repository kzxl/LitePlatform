<?php

declare(strict_types=1);

namespace App\Controllers;

use DI\Container;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Controller demonstrating JSON REST API responses with LiteApi.
 */
class ApiController
{
    public function __construct(private Container $container)
    {
    }

    /**
     * Return JSON system status & active modules.
     */
    public function status(Request $request, Response $response): Response
    {
        $config = $this->container->has('config') ? (array)$this->container->get('config') : [];
        $isProd = (string)($config['env'] ?? 'development') === 'production';

        $data = $isProd
            ? [
                'status'    => 'healthy',
                'timestamp' => time(),
            ]
            : [
                'status'      => 'healthy',
                'framework'   => 'LitePlatform Skeleton',
                'php_version' => PHP_VERSION,
                'memory_mb'   => round(memory_get_usage(true) / 1024 / 1024, 2),
                'timestamp'   => time(),
                'modules'     => [
                    'orm'      => class_exists(\LiteORM\EntityManager::class),
                    'view'     => class_exists(\LiteView\ViewEngine::class),
                    'admin'    => class_exists(\LiteAdmin\AdminDashboard::class),
                    'api'      => class_exists(\LiteApi\Security\Jwt::class),
                    'queue'    => class_exists(\LiteQueue\QueueManager::class),
                    'validate' => class_exists(\LiteValidate\Validator::class),
                    'export'   => class_exists(\LiteExport\Exporter::class),
                    'audit'    => class_exists(\LiteAudit\AuditManager::class),
                    'inertia'  => class_exists(\LiteInertia\Inertia::class),
                    'util'     => class_exists(\LiteUtil\Number\NumberToWords::class),
                ],
            ];

        if (class_exists(\LiteApi\Http\ApiResponse::class)) {
            return \LiteApi\Http\ApiResponse::ok($response, $data);
        }

        $response->getBody()->write(json_encode($data, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
        return $response->withHeader('Content-Type', 'application/json');
    }

    /**
     * Safe projected users list endpoint (never exposes emails, password_hash, or 2FA secrets publicly).
     */
    public function users(Request $request, Response $response): Response
    {
        $safeUsers = [];

        if ($this->container->has(\PDO::class)) {
            try {
                $stmt = $this->container->get(\PDO::class)->query('SELECT id, name, role FROM users ORDER BY id ASC LIMIT 20');
                $rows = $stmt ? ($stmt->fetchAll(\PDO::FETCH_ASSOC) ?: []) : [];
                foreach ($rows as $row) {
                    $safeUsers[] = [
                        'id'   => (int)$row['id'],
                        'name' => (string)$row['name'],
                        'role' => (string)$row['role'],
                    ];
                }
            } catch (\Throwable) {
                $safeUsers = [];
            }
        }

        if (class_exists(\LiteApi\Http\ApiResponse::class)) {
            return \LiteApi\Http\ApiResponse::ok($response, $safeUsers, ['count' => count($safeUsers)]);
        }

        $response->getBody()->write(json_encode(['success' => true, 'data' => $safeUsers], JSON_THROW_ON_ERROR));
        return $response->withHeader('Content-Type', 'application/json');
    }

    /**
     * Interactive Zero-Dependency API Documentation Page.
     */
    public function docs(Request $request, Response $response): Response
    {
        $html = <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>REST API Documentation | LitePlatform</title>
    <style>
        :root {
            --bg-color: #0d1117;
            --surface-color: #161b22;
            --border-color: #30363d;
            --text-primary: #f0f6fc;
            --text-secondary: #8b949e;
            --accent: #58a6ff;
            --get: #388bfd;
            --post: #3fb950;
            --put: #d29922;
            --delete: #f85149;
        }
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: var(--bg-color);
            color: var(--text-primary);
            padding: 2rem;
            max-width: 1000px;
            margin: 0 auto;
            line-height: 1.6;
        }
        .header { margin-bottom: 2rem; padding-bottom: 1rem; border-bottom: 1px solid var(--border-color); }
        .endpoint-card {
            background: var(--surface-color);
            border: 1px solid var(--border-color);
            border-radius: 8px;
            margin-bottom: 1rem;
            padding: 1rem 1.25rem;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }
        .method {
            font-size: 0.75rem;
            font-weight: 700;
            padding: 0.25rem 0.6rem;
            border-radius: 6px;
            color: #fff;
            width: 70px;
            text-align: center;
            display: inline-block;
        }
        .method-get { background: var(--get); }
        .method-post { background: var(--post); }
        .method-put { background: var(--put); }
        .method-delete { background: var(--delete); }
        .path { font-family: monospace; font-size: 0.95rem; margin-left: 1rem; font-weight: 600; }
        .btn-try {
            background: transparent;
            border: 1px solid var(--border-color);
            color: var(--accent);
            padding: 0.4rem 0.85rem;
            border-radius: 6px;
            cursor: pointer;
            font-weight: 600;
            font-size: 0.85rem;
        }
        .btn-try:hover { background: rgba(88, 166, 255, 0.1); border-color: var(--accent); }
    </style>
</head>
<body>
    <div class="header">
        <h1>⚡ LitePlatform API Reference</h1>
        <p style="color: var(--text-secondary);">Interactive, zero-dependency REST API documentation.</p>
    </div>

    <div class="endpoint-card">
        <div>
            <span class="method method-get">GET</span>
            <span class="path">/api/status</span>
        </div>
        <button class="btn-try" onclick="testApi('GET', '/api/status')">Try it out</button>
    </div>

    <div class="endpoint-card">
        <div>
            <span class="method method-get">GET</span>
            <span class="path">/api/users</span>
        </div>
        <button class="btn-try" onclick="testApi('GET', '/api/users')">Try it out</button>
    </div>

    <div class="endpoint-card">
        <div>
            <span class="method method-post">POST</span>
            <span class="path">/api/login</span>
        </div>
        <button class="btn-try" onclick="testApi('POST', '/api/login', {email: 'admin@liteplatform.io', password: 'YOUR_PASSWORD'})">Try it out</button>
    </div>

    <div id="result-box" style="display: none; margin-top: 1.5rem; background: #000; border: 1px solid var(--border-color); border-radius: 8px; padding: 1.25rem;">
        <h4 style="margin-bottom: 0.5rem; color: var(--accent);" id="result-title">Response</h4>
        <pre style="color: #79c0ff; font-family: monospace; font-size: 0.85rem; overflow-x: auto;" id="result-body"></pre>
    </div>

    <script>
        async function testApi(method, path, body = null) {
            const box = document.getElementById('result-box');
            const title = document.getElementById('result-title');
            const result = document.getElementById('result-body');
            box.style.display = 'block';
            title.innerText = method + ' ' + path + ' (Loading...)';
            result.innerText = '';

            try {
                const opts = { method: method };
                if (body) {
                    opts.headers = { 'Content-Type': 'application/json' };
                    opts.body = JSON.stringify(body);
                }
                const res = await fetch(path, opts);
                const data = await res.json();
                title.innerText = method + ' ' + path + ' [' + res.status + ']';
                result.innerText = JSON.stringify(data, null, 2);
            } catch (err) {
                title.innerText = method + ' ' + path + ' [Error]';
                result.innerText = err.message;
            }
        }
    </script>
</body>
</html>
HTML;

        $response->getBody()->write($html);
        return $response->withHeader('Content-Type', 'text/html; charset=utf-8');
    }
}
