<?php

declare(strict_types=1);

namespace App\Controllers;

use DI\Container;
use Lite\Framework\Security\AdminToken;
use Lite\Framework\Security\LoginThrottle;
use LiteCache\CacheInterface;
use Nyholm\Psr7\Response;
use PDO;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Hardened authentication controller for Admin Dashboard and REST API.
 * - Verifies credentials against database `users.password_hash` via password_verify().
 * - Protects against brute-force attacks using LiteCache-backed LoginThrottle.
 * - Issues TTL-bound HMAC-SHA256 cookies (Admin) and HS256 JWTs (API).
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
        return $this->renderLoginPage($request, $response);
    }

    private function renderLoginPage(Request $request, ResponseInterface $response, string $error = ''): ResponseInterface
    {
        $csrfToken = htmlspecialchars((string)$request->getAttribute('csrf_token', ''), ENT_QUOTES, 'UTF-8');
        $errorBlock = $error !== ''
            ? '<div class="alert">' . htmlspecialchars($error, ENT_QUOTES, 'UTF-8') . '</div>'
            : '';

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
            --danger: #f85149;
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
        }
        .brand { text-align: center; margin-bottom: 1.75rem; }
        .brand h2 { font-size: 1.5rem; font-weight: 700; margin-bottom: 0.25rem; }
        .brand p { font-size: 0.85rem; color: var(--text-secondary); }
        .alert {
            margin-bottom: 1rem;
            padding: 0.65rem 0.85rem;
            border-radius: 8px;
            background: rgba(248, 81, 73, 0.12);
            border: 1px solid rgba(248, 81, 73, 0.35);
            color: #ff7b72;
            font-size: 0.85rem;
        }
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
            margin-top: 0.5rem;
        }
        button:hover { background: var(--accent-hover); }
    </style>
</head>
<body>
    <div class="login-card">
        <div class="brand">
            <h2>LiteAdmin</h2>
            <p>Sovereign Dashboard Authentication</p>
        </div>
        {$errorBlock}
        <form method="POST" action="/admin/login">
            <input type="hidden" name="_csrf" value="{$csrfToken}">
            <div class="form-group">
                <label for="username">Email</label>
                <input type="text" id="username" name="username" placeholder="admin@liteplatform.io" autocomplete="username" required autofocus>
            </div>
            <div class="form-group">
                <label for="password">Password</label>
                <input type="password" id="password" name="password" autocomplete="current-password" required>
            </div>
            <button type="submit">Sign In</button>
        </form>
    </div>
</body>
</html>
HTML;

        $response->getBody()->write($html);
        return $response->withHeader('Content-Type', 'text/html; charset=utf-8');
    }

    /**
     * Process admin login submission against DB password_hash with brute-force throttling.
     */
    public function processLogin(Request $request, ResponseInterface $response): ResponseInterface
    {
        $body = (array)$request->getParsedBody();
        $login = trim((string)($body['username'] ?? $body['email'] ?? ''));
        $password = (string)($body['password'] ?? '');

        $throttle = $this->getThrottle();
        $throttleKey = 'admin|' . $this->resolveClientIp($request) . '|' . strtolower($login);

        if ($throttle !== null && $throttle->tooManyAttempts($throttleKey)) {
            $retryAfter = $throttle->availableIn($throttleKey);
            return $this->renderLoginPage(
                $request,
                $response->withStatus(429)->withHeader('Retry-After', (string)$retryAfter),
                "Too many login attempts. Try again in {$retryAfter} seconds."
            );
        }

        $user = $this->authenticateUser($login, $password, requireAdmin: true);
        if ($user !== null) {
            $throttle?->clear($throttleKey);

            $config = (array)$this->container->get('config');
            $env = (string)($config['env'] ?? 'development');
            $secret = (string)(
                $config['admin']['auth_secret']
                ?? $config['security']['csrf_secret']
                ?? $_ENV['APP_KEY']
                ?? AdminToken::DEFAULT_INSECURE_SECRET
            );
            AdminToken::assertSecretSafe($secret, $env);

            $ttl = (int)($config['security']['admin_ttl'] ?? 28800);
            $tokenVersion = (int)($user['token_version'] ?? 1);
            $cookieValue = AdminToken::issue((string)$user['email'], $secret, $ttl, tokenVersion: $tokenVersion);
            $secureFlag = (bool)($config['security']['cookie_secure'] ?? false) ? '; Secure' : '';

            if ($this->container->has(\Lite\Framework\Security\SecurityAuditLog::class)) {
                try {
                    $this->container->get(\Lite\Framework\Security\SecurityAuditLog::class)->record(
                        eventType: 'auth.login_success',
                        severity: 'INFO',
                        actor: (string)$user['email'],
                        actorIp: $this->resolveClientIp($request),
                        target: '/admin/login'
                    );
                } catch (\Throwable) {
                }
            }

            return (new Response())
                ->withStatus(302)
                ->withHeader('Location', '/admin')
                ->withHeader(
                    'Set-Cookie',
                    "lite_admin_auth={$cookieValue}; Path=/admin; Max-Age={$ttl}; HttpOnly; SameSite=Lax{$secureFlag}"
                );
        }

        $throttle?->hit($throttleKey);
        if ($this->container->has(\Lite\Framework\Security\SecurityAuditLog::class)) {
            try {
                $this->container->get(\Lite\Framework\Security\SecurityAuditLog::class)->record(
                    eventType: 'auth.login_failed',
                    severity: 'WARN',
                    actor: $login !== '' ? $login : 'unknown',
                    actorIp: $this->resolveClientIp($request),
                    target: '/admin/login'
                );
            } catch (\Throwable) {
            }
        }
        return $this->renderLoginPage($request, $response->withStatus(401), 'Invalid email or password.');
    }

    /**
     * Log out from admin dashboard.
     */
    public function logout(Request $request, ResponseInterface $response): ResponseInterface
    {
        return (new Response())
            ->withStatus(302)
            ->withHeader('Location', '/admin/login')
            ->withHeader('Set-Cookie', 'lite_admin_auth=; Path=/admin; Expires=Thu, 01 Jan 1970 00:00:00 GMT; HttpOnly; SameSite=Lax');
    }

    /**
     * Issue HS256 JWT for API clients against DB password_hash.
     */
    public function apiLogin(Request $request, ResponseInterface $response): ResponseInterface
    {
        $body = (array)$request->getParsedBody();
        $email = trim((string)($body['email'] ?? ''));
        $password = (string)($body['password'] ?? '');

        $throttle = $this->getThrottle();
        $throttleKey = 'api|' . $this->resolveClientIp($request) . '|' . strtolower($email);

        if ($throttle !== null && $throttle->tooManyAttempts($throttleKey)) {
            $retryAfter = $throttle->availableIn($throttleKey);
            $response->getBody()->write(json_encode([
                'success'     => false,
                'error'       => 'Too many login attempts',
                'retry_after' => $retryAfter,
            ], JSON_THROW_ON_ERROR));

            return $response
                ->withStatus(429)
                ->withHeader('Retry-After', (string)$retryAfter)
                ->withHeader('Content-Type', 'application/json');
        }

        $user = $this->authenticateUser($email, $password, requireAdmin: false);
        if ($user === null) {
            $throttle?->hit($throttleKey);
            $response->getBody()->write(json_encode([
                'success' => false,
                'error'   => 'Invalid email or password',
            ], JSON_THROW_ON_ERROR));

            return $response->withStatus(401)->withHeader('Content-Type', 'application/json');
        }

        $throttle?->clear($throttleKey);

        if (!class_exists(\LiteApi\Security\Jwt::class)) {
            $response->getBody()->write(json_encode([
                'success' => false,
                'error'   => 'JWT signing module is unavailable',
            ], JSON_THROW_ON_ERROR));

            return $response->withStatus(500)->withHeader('Content-Type', 'application/json');
        }

        $jwtSecret = (string)$this->container->get('jwt.secret');
        $token = \LiteApi\Security\Jwt::sign([
            'sub'   => (int)$user['id'],
            'email' => (string)$user['email'],
            'role'  => (string)$user['role'],
        ], $jwtSecret, ttl: 3600);

        $data = [
            'token'      => $token,
            'token_type' => 'Bearer',
            'expires_in' => 3600,
        ];

        if (class_exists(\LiteApi\Http\ApiResponse::class)) {
            return \LiteApi\Http\ApiResponse::ok($response, $data);
        }

        $response->getBody()->write(json_encode(['success' => true, 'data' => $data], JSON_THROW_ON_ERROR));
        return $response->withHeader('Content-Type', 'application/json');
    }

    /**
     * Authenticate user via parameterized PDO query + password_verify().
     *
     * @return array{id: int|string, name: string, email: string, role: string, password_hash: string}|null
     */
    private function authenticateUser(string $login, string $password, bool $requireAdmin): ?array
    {
        if ($login === '' || $password === '' || !$this->container->has(PDO::class)) {
            return null;
        }

        /** @var PDO $pdo */
        $pdo = $this->container->get(PDO::class);

        $stmt = $pdo->prepare(
            'SELECT id, name, email, role, password_hash, COALESCE(token_version, 1) AS token_version, COALESCE(is_active, 1) AS is_active FROM users WHERE LOWER(email) = LOWER(:login) LIMIT 1'
        );
        $stmt->execute([':login' => $login]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!is_array($row)) {
            // Timing-attack mitigation: perform dummy hash check even when user does not exist
            password_verify($password, '$2y$10$abcdefghijklmnopqrstuv1234567890123456789012345678901');
            return null;
        }

        if ((int)($row['is_active'] ?? 1) !== 1) {
            return null;
        }

        $hash = (string)($row['password_hash'] ?? '');
        if ($hash === '' || !password_verify($password, $hash)) {
            return null;
        }

        $role = strtolower((string)($row['role'] ?? ''));
        if ($requireAdmin && !in_array($role, ['admin', 'superadmin', 'manager', 'staff'], true)) {
            return null;
        }

        return $row;
    }

    private function getThrottle(): ?LoginThrottle
    {
        if (!$this->container->has(CacheInterface::class)) {
            return null;
        }

        return new LoginThrottle($this->container->get(CacheInterface::class));
    }

    private function resolveClientIp(Request $request): string
    {
        $resolved = $request->getAttribute('client_ip');
        if (is_string($resolved) && $resolved !== '') {
            return $resolved;
        }

        $server = $request->getServerParams();
        return (string)($server['REMOTE_ADDR'] ?? '127.0.0.1');
    }
}
