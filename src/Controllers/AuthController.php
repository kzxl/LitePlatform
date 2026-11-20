<?php

declare(strict_types=1);

namespace App\Controllers;

use DI\Container;
use Nyholm\Psr7\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Controller handling authentication for Admin Dashboard and REST API.
 */
class AuthController
{
    public function __construct(private Container $container)
    {
    }

    /**
     * Render the admin login view.
     */
    public function showLogin(Request $request, ResponseInterface $response): ResponseInterface
    {
        $csrfToken = htmlspecialchars((string)$request->getAttribute('csrf_token', ''), ENT_QUOTES, 'UTF-8');
        $html = <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Login | LitePlatform</title>
    <style>
        :root {
            --bg-color: #0d1117;
            --surface-color: #161b22;
            --border-color: #30363d;
            --text-primary: #f0f6fc;
            --text-secondary: #8b949e;
            --accent: #58a6ff;
            --accent-hover: #79c0ff;
        }
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: var(--bg-color);
            color: var(--text-primary);
            display: flex;
            align-items: center;
            justify-content: center;
            min-height: 100vh;
            padding: 1rem;
        }
        .login-card {
            background: var(--surface-color);
            border: 1px solid var(--border-color);
            border-radius: 12px;
            width: 100%;
            max-width: 380px;
            padding: 2.25rem;
            box-shadow: 0 16px 32px rgba(0,0,0,0.4);
        }
        .brand { text-align: center; margin-bottom: 1.75rem; }
        .brand h2 { font-size: 1.5rem; font-weight: 700; margin-bottom: 0.25rem; }
        .brand p { font-size: 0.85rem; color: var(--text-secondary); }
        .form-group { margin-bottom: 1.25rem; }
        label { display: block; font-size: 0.85rem; font-weight: 600; margin-bottom: 0.5rem; }
        input {
            width: 100%;
            padding: 0.75rem 1rem;
            background: #0d1117;
            border: 1px solid var(--border-color);
            border-radius: 8px;
            color: var(--text-primary);
            font-size: 0.95rem;
            outline: none;
            transition: border-color 0.2s ease;
        }
        input:focus { border-color: var(--accent); }
        button {
            width: 100%;
            padding: 0.75rem;
            background: var(--accent);
            color: #0d1117;
            border: none;
            border-radius: 8px;
            font-weight: 700;
            font-size: 0.95rem;
            cursor: pointer;
            transition: background 0.2s ease;
            margin-top: 0.5rem;
        }
        button:hover { background: var(--accent-hover); }
        .hint {
            margin-top: 1.25rem;
            font-size: 0.8rem;
            color: var(--text-secondary);
            text-align: center;
            background: rgba(88, 166, 255, 0.1);
            border: 1px solid rgba(88, 166, 255, 0.2);
            padding: 0.5rem;
            border-radius: 6px;
        }
    </style>
</head>
<body>
    <div class="login-card">
        <div class="brand">
            <h2>⚡ LiteAdmin</h2>
            <p>Sovereign Dashboard Authentication</p>
        </div>
        <form method="POST" action="/admin/login">
            <input type="hidden" name="_csrf" value="{$csrfToken}">
            <div class="form-group">
                <label for="username">Username</label>
                <input type="text" id="username" name="username" value="admin" required autofocus>
            </div>
            <div class="form-group">
                <label for="password">Password</label>
                <input type="password" id="password" name="password" value="admin123" required>
            </div>
            <button type="submit">Sign In</button>
        </form>
        <div class="hint">
            Demo credentials: <strong>admin</strong> / <strong>admin123</strong>
        </div>
    </div>
</body>
</html>
HTML;

        $response->getBody()->write($html);
        return $response->withHeader('Content-Type', 'text/html; charset=utf-8');
    }

    /**
     * Process admin login submission.
     */
    public function processLogin(Request $request, ResponseInterface $response): ResponseInterface
    {
        $body = (array)$request->getParsedBody();
        $username = trim($body['username'] ?? '');
        $password = (string)($body['password'] ?? '');

        // Default demo credentials: admin / admin123
        if ($username === 'admin' && $password === 'admin123') {
            $config = (array)$this->container->get('config');
            $env = (string)($config['env'] ?? 'development');
            $secret = (string)(
                $config['admin']['auth_secret']
                ?? $config['security']['csrf_secret']
                ?? $_ENV['APP_KEY']
                ?? \App\Security\AdminToken::DEFAULT_INSECURE_SECRET
            );
            \App\Security\AdminToken::assertSecretSafe($secret, $env);

            $ttl = (int)($config['security']['admin_ttl'] ?? 28800);
            $cookieValue = \App\Security\AdminToken::issue($username, $secret, $ttl);
            $secureFlag = (bool)($config['security']['cookie_secure'] ?? false) ? '; Secure' : '';

            return (new Response())
                ->withStatus(302)
                ->withHeader('Location', '/admin')
                ->withHeader(
                    'Set-Cookie',
                    "lite_admin_auth={$cookieValue}; Path=/admin; Max-Age={$ttl}; HttpOnly; SameSite=Lax{$secureFlag}"
                );
        }

        return $this->showLogin($request, $response->withStatus(401));
    }

    /**
     * Log out from admin dashboard.
     */
    public function logout(Request $request, ResponseInterface $response): ResponseInterface
    {
        return (new Response())
            ->withStatus(302)
            ->withHeader('Location', '/admin/login')
            ->withHeader('Set-Cookie', "lite_admin_auth=; Path=/admin; Expires=Thu, 01 Jan 1970 00:00:00 GMT");
    }

    /**
     * Issue JWT for API clients.
     */
    public function apiLogin(Request $request, ResponseInterface $response): ResponseInterface
    {
        $body = (array)$request->getParsedBody();
        $email = trim($body['email'] ?? '');
        $password = (string)($body['password'] ?? '');

        // Verify credentials (Demo: admin@liteplatform.io / secret)
        if (($email === 'admin@liteplatform.io' || $email === 'admin') && ($password === 'secret' || $password === 'admin123')) {
            $jwtSecret = $this->container->get('jwt.secret');

            if (class_exists(\LiteApi\Security\Jwt::class)) {
                $token = \LiteApi\Security\Jwt::sign([
                    'sub'   => 1,
                    'email' => $email,
                    'role'  => 'admin',
                ], $jwtSecret, ttl: 3600);
            } else {
                $token = base64_encode(json_encode(['email' => $email, 'time' => time()]));
            }

            $data = [
                'token'      => $token,
                'token_type' => 'Bearer',
                'expires_in' => 3600,
            ];

            if (class_exists(\LiteApi\Http\ApiResponse::class)) {
                return \LiteApi\Http\ApiResponse::ok($response, $data);
            }

            $response->getBody()->write(json_encode(['success' => true, 'data' => $data]));
            return $response->withHeader('Content-Type', 'application/json');
        }

        $error = ['success' => false, 'error' => 'Invalid email or password'];
        $response->getBody()->write(json_encode($error));
        return $response->withStatus(401)->withHeader('Content-Type', 'application/json');
    }
}
