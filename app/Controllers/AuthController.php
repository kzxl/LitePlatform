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
            <div class="form-group">
                <label for="totp_code">2FA Code <span style="font-weight:400;color:var(--text-secondary);">(if enabled)</span></label>
                <input type="text" id="totp_code" name="totp_code" inputmode="numeric" pattern="[0-9\s]*" placeholder="6-digit authenticator code" autocomplete="one-time-code">
            </div>
            <button type="submit">Sign In</button>
            <div style="text-align:center;margin-top:1rem;">
                <a href="/admin/forgot-password" style="color:var(--accent);font-size:0.84rem;text-decoration:none;">Forgot password?</a>
            </div>
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
        $ipThrottle = $this->getIpSprayThrottle();
        $clientIp = $this->resolveClientIp($request);
        $throttleKey = 'admin|' . $clientIp . '|' . strtolower($login);
        $ipKey = 'admin_ip|' . $clientIp;

        if (($throttle !== null && $throttle->tooManyAttempts($throttleKey)) || ($ipThrottle !== null && $ipThrottle->tooManyAttempts($ipKey))) {
            $retryAfter = max(
                $throttle ? $throttle->availableIn($throttleKey) : 0,
                $ipThrottle ? $ipThrottle->availableIn($ipKey) : 0,
                1
            );
            return $this->renderLoginPage(
                $request,
                $response->withStatus(429)->withHeader('Retry-After', (string)$retryAfter),
                "Too many login attempts. Try again in {$retryAfter} seconds."
            );
        }

        $user = $this->authenticateUser($login, $password, requireAdmin: true);
        if ($user !== null) {
            // Verify TOTP 2FA if enabled for this account
            $encryptedTotp = trim((string)($user['totp_secret'] ?? ''));
            if ($encryptedTotp !== '') {
                $totpCode = trim((string)($body['totp_code'] ?? ''));
                $totpValid = false;
                if ($totpCode !== '' && $this->container->has(\Lite\Framework\Security\SecretVault::class)) {
                    try {
                        $plainSecret = $this->container->get(\Lite\Framework\Security\SecretVault::class)
                            ->decrypt($encryptedTotp, 'user_2fa', 'u_' . (int)$user['id']);
                        $totpValid = (new \Lite\Framework\Security\TotpAuthenticator())->verify($plainSecret, $totpCode);
                    } catch (\Throwable) {
                        $totpValid = false;
                    }
                }
                if (!$totpValid) {
                    $throttle?->hit($throttleKey);
        $ipThrottle?->hit($ipKey);
                    if ($this->container->has(\Lite\Framework\Security\SecurityAuditLog::class)) {
                        try {
                            $this->container->get(\Lite\Framework\Security\SecurityAuditLog::class)->record(
                                eventType: 'auth.2fa_failed',
                                severity: 'WARN',
                                actor: (string)$user['email'],
                                actorIp: $this->resolveClientIp($request),
                                target: '/admin/login'
                            );
                        } catch (\Throwable) {
                        }
                    }
                    return $this->renderLoginPage($request, $response->withStatus(401), 'Invalid or missing 2FA authenticator code.');
                }
            }

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
        $ipThrottle?->hit($ipKey);
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
        $config = $this->container->has('config') ? (array)$this->container->get('config') : [];
        $secureFlag = (bool)($config['security']['cookie_secure'] ?? false) ? '; Secure' : '';

        return (new Response())
            ->withStatus(302)
            ->withHeader('Location', '/admin/login')
            ->withHeader('Set-Cookie', "lite_admin_auth=; Path=/admin; Expires=Thu, 01 Jan 1970 00:00:00 GMT; HttpOnly; SameSite=Lax{$secureFlag}");
    }

    public function showForgotPassword(Request $request, ResponseInterface $response): ResponseInterface
    {
        return $this->renderRecoveryPage($request, $response, mode: 'forgot');
    }

    public function sendResetLink(Request $request, ResponseInterface $response): ResponseInterface
    {
        $body = (array)$request->getParsedBody();
        $email = trim((string)($body['email'] ?? ''));
        $neutralMsg = 'If an active account matches that email, a password reset link has been sent (valid for 30 minutes).';

        if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) && $this->container->has(PDO::class)) {
            /** @var PDO $pdo */
            $pdo = $this->container->get(PDO::class);
            $stmt = $pdo->prepare('SELECT id, email, COALESCE(token_version, 1) AS token_version, COALESCE(is_active, 1) AS is_active FROM users WHERE LOWER(email) = LOWER(:e) LIMIT 1');
            $stmt->execute([':e' => $email]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);

            if (is_array($user) && (int)$user['is_active'] === 1) {
                $token = $this->issueResetToken((string)$user['email'], (int)$user['token_version']);
                $appUrl = rtrim((string)($this->container->get('config')['url'] ?? 'http://localhost:8000'), '/');
                $resetUrl = $appUrl . '/admin/reset-password?token=' . rawurlencode($token);

                if ($this->container->has(\Lite\Framework\Mail\Mailer::class)) {
                    $eUrl = htmlspecialchars($resetUrl, ENT_QUOTES, 'UTF-8');
                    try {
                        $this->container->get(\Lite\Framework\Mail\Mailer::class)->send(
                            (string)$user['email'],
                            'Password Reset Request',
                            "<p>Click the link below to reset your password (expires in 30 minutes):</p><p><a href=\"{$eUrl}\">{$eUrl}</a></p>"
                        );
                    } catch (\Throwable) {
                    }
                }
            }
        }

        return $this->renderRecoveryPage($request, $response, mode: 'forgot', success: $neutralMsg);
    }

    public function showResetPassword(Request $request, ResponseInterface $response): ResponseInterface
    {
        $token = trim((string)($request->getQueryParams()['token'] ?? ''));
        return $this->renderRecoveryPage($request, $response, mode: 'reset', token: $token);
    }

    public function processResetPassword(Request $request, ResponseInterface $response): ResponseInterface
    {
        $body = (array)$request->getParsedBody();
        $token = trim((string)($body['token'] ?? ''));
        $newPassword = (string)($body['password'] ?? '');
        $confirm = (string)($body['password_confirm'] ?? '');

        if (strlen($newPassword) < 8 || $newPassword !== $confirm) {
            return $this->renderRecoveryPage($request, $response->withStatus(422), mode: 'reset', token: $token, error: 'Password must be at least 8 characters and match confirmation.');
        }

        $payload = $this->verifyResetToken($token);
        if ($payload === null || !$this->container->has(PDO::class)) {
            return $this->renderRecoveryPage($request, $response->withStatus(422), mode: 'reset', token: $token, error: 'Reset token is invalid or expired.');
        }

        /** @var PDO $pdo */
        $pdo = $this->container->get(PDO::class);
        $stmt = $pdo->prepare('SELECT id, COALESCE(token_version, 1) AS token_version, COALESCE(is_active, 1) AS is_active FROM users WHERE LOWER(email) = LOWER(:e) LIMIT 1');
        $stmt->execute([':e' => $payload['username']]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!is_array($user) || (int)$user['is_active'] !== 1 || (int)$user['token_version'] !== $payload['token_version']) {
            return $this->renderRecoveryPage($request, $response->withStatus(422), mode: 'reset', token: $token, error: 'Reset token has already been used or revoked.');
        }

        $hash = password_hash($newPassword, PASSWORD_BCRYPT);
        $pdo->prepare('UPDATE users SET password_hash = :h, token_version = COALESCE(token_version, 1) + 1 WHERE id = :id')
            ->execute([':h' => $hash, ':id' => (int)$user['id']]);

        if ($this->container->has(\Lite\Framework\Security\RbacManager::class)) {
            $this->container->get(\Lite\Framework\Security\RbacManager::class)->invalidateCache();
        }
        if ($this->container->has(\Lite\Framework\Security\SecurityAuditLog::class)) {
            try {
                $this->container->get(\Lite\Framework\Security\SecurityAuditLog::class)->record(
                    eventType: 'auth.password_reset',
                    severity: 'HIGH',
                    actor: $payload['username'],
                    actorIp: $this->resolveClientIp($request),
                    target: 'user#' . (int)$user['id']
                );
            } catch (\Throwable) {
            }
        }

        return $this->renderLoginPage($request, $response, 'Password reset successfully. Please sign in with your new password.');
    }

    public function issueResetToken(string $email, int $tokenVersion, int $ttlSeconds = 1800): string
    {
        $config = (array)$this->container->get('config');
        $secret = hash_hmac('sha256', 'password-reset-v1', (string)($config['security']['csrf_secret'] ?? AdminToken::DEFAULT_INSECURE_SECRET));
        return AdminToken::issue($email, $secret, $ttlSeconds, tokenVersion: $tokenVersion);
    }

    /** @return array{username: string, expires_at: int, token_version: int}|null */
    private function verifyResetToken(string $token): ?array
    {
        $config = (array)$this->container->get('config');
        $secret = hash_hmac('sha256', 'password-reset-v1', (string)($config['security']['csrf_secret'] ?? AdminToken::DEFAULT_INSECURE_SECRET));
        return AdminToken::verifyPayload($token, $secret);
    }

    private function renderRecoveryPage(Request $request, ResponseInterface $response, string $mode, string $token = '', string $error = '', string $success = ''): ResponseInterface
    {
        $csrf = htmlspecialchars((string)$request->getAttribute('csrf_token', ''), ENT_QUOTES, 'UTF-8');
        $e = static fn(string $v): string => htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
        $msgHtml = $error !== '' ? '<div style="margin-bottom:1rem;padding:0.65rem;border-radius:8px;background:rgba(248,81,73,0.12);color:#ff7b72;font-size:0.85rem;">' . $e($error) . '</div>' : '';
        if ($success !== '') {
            $msgHtml .= '<div style="margin-bottom:1rem;padding:0.65rem;border-radius:8px;background:rgba(63,185,80,0.15);color:#7ee787;font-size:0.85rem;">' . $e($success) . '</div>';
        }

        if ($mode === 'reset') {
            $title = 'Reset Password';
            $form = '<form method="POST" action="/admin/reset-password">'
                . '<input type="hidden" name="_csrf" value="' . $csrf . '">'
                . '<input type="hidden" name="token" value="' . $e($token) . '">'
                . '<div style="margin-bottom:1rem;"><label style="display:block;font-size:0.85rem;margin-bottom:0.4rem;">New Password (min 8 chars)</label><input type="password" name="password" required minlength="8" style="width:100%;padding:0.7rem;background:#0d1117;border:1px solid #30363d;border-radius:8px;color:#f0f6fc;"></div>'
                . '<div style="margin-bottom:1.25rem;"><label style="display:block;font-size:0.85rem;margin-bottom:0.4rem;">Confirm Password</label><input type="password" name="password_confirm" required minlength="8" style="width:100%;padding:0.7rem;background:#0d1117;border:1px solid #30363d;border-radius:8px;color:#f0f6fc;"></div>'
                . '<button type="submit" style="width:100%;padding:0.75rem;background:#58a6ff;color:#0d1117;border:none;border-radius:8px;font-weight:700;cursor:pointer;">Update Password</button>'
                . '</form>';
        } else {
            $title = 'Forgot Password';
            $form = '<form method="POST" action="/admin/forgot-password">'
                . '<input type="hidden" name="_csrf" value="' . $csrf . '">'
                . '<div style="margin-bottom:1.25rem;"><label style="display:block;font-size:0.85rem;margin-bottom:0.4rem;">Account Email</label><input type="email" name="email" required placeholder="admin@liteplatform.io" style="width:100%;padding:0.7rem;background:#0d1117;border:1px solid #30363d;border-radius:8px;color:#f0f6fc;"></div>'
                . '<button type="submit" style="width:100%;padding:0.75rem;background:#58a6ff;color:#0d1117;border:none;border-radius:8px;font-weight:700;cursor:pointer;">Send Reset Link</button>'
                . '</form>';
        }

        $html = "<!DOCTYPE html><html lang=\"en\"><head><meta charset=\"UTF-8\"><meta name=\"viewport\" content=\"width=device-width,initial-scale=1\"><title>{$title} | LiteAdmin</title></head>"
            . "<body style=\"font-family:system-ui,sans-serif;background:#0d1117;color:#f0f6fc;display:flex;align-items:center;justify-content:center;min-height:100vh;padding:1rem;margin:0;\">"
            . "<div style=\"background:#161b22;border:1px solid #30363d;border-radius:12px;max-width:390px;width:100%;padding:2rem;\">"
            . "<h2 style=\"margin:0 0 0.4rem;\">{$title}</h2><p style=\"margin:0 0 1.25rem;color:#8b949e;font-size:0.85rem;\">LiteAdmin Account Recovery</p>{$msgHtml}{$form}"
            . "<div style=\"text-align:center;margin-top:1rem;\"><a href=\"/admin/login\" style=\"color:#58a6ff;font-size:0.84rem;text-decoration:none;\">&larr; Back to Sign In</a></div></div></body></html>";

        $response->getBody()->write($html);
        return $response->withHeader('Content-Type', 'text/html; charset=utf-8');
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
        $ipThrottle = $this->getIpSprayThrottle();
        $clientIp = $this->resolveClientIp($request);
        $throttleKey = 'api|' . $clientIp . '|' . strtolower($email);
        $ipKey = 'api_ip|' . $clientIp;

        if (($throttle !== null && $throttle->tooManyAttempts($throttleKey)) || ($ipThrottle !== null && $ipThrottle->tooManyAttempts($ipKey))) {
            $retryAfter = max(
                $throttle ? $throttle->availableIn($throttleKey) : 0,
                $ipThrottle ? $ipThrottle->availableIn($ipKey) : 0,
                1
            );
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
        $ipThrottle?->hit($ipKey);
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
            'SELECT id, name, email, role, password_hash, COALESCE(token_version, 1) AS token_version, COALESCE(is_active, 1) AS is_active, totp_secret FROM users WHERE LOWER(email) = LOWER(:login) LIMIT 1'
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

    /**
     * Secondary IP-only throttle defending against multi-account password spraying attacks.
     */
    private function getIpSprayThrottle(): ?LoginThrottle
    {
        if (!$this->container->has(CacheInterface::class)) {
            return null;
        }

        return new LoginThrottle($this->container->get(CacheInterface::class), maxAttempts: 20, decaySeconds: 300);
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
