<?php

declare(strict_types=1);

namespace Lite\Framework\Admin;

use DI\Container;
use Lite\Framework\Security\AdminToken;
use Lite\Framework\Security\RbacManager;
use Lite\Framework\Security\SecretVault;
use Lite\Framework\Security\SecurityAuditLog;
use Lite\Framework\Security\TotpAuthenticator;
use LiteAdmin\AdminDashboard;
use PDO;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Self-service Account Security Controller (`/admin/profile`):
 * - Change Password (re-verifies current password, bumps `token_version` to revoke other devices, re-issues cookie)
 * - Enable / Disable RFC 6238 TOTP 2FA (stores secret encrypted inside SecretVault AEAD)
 */
final class AccountAdminController
{
    public function __construct(private readonly Container $container)
    {
    }

    public function show(Request $request, Response $response): Response
    {
        return $this->render($request, $response);
    }

    public function handlePost(Request $request, Response $response): Response
    {
        $body = (array)$request->getParsedBody();
        $action = trim((string)($body['action'] ?? ''));
        $user = $this->resolveCurrentUser($request);
        if ($user === null) {
            return $this->render($request, $response->withStatus(400), flashError: 'Account record not found in database.');
        }

        /** @var PDO $pdo */
        $pdo = $this->container->get(PDO::class);
        $audit = $this->container->has(SecurityAuditLog::class) ? $this->container->get(SecurityAuditLog::class) : null;
        $ip = (string)($request->getAttribute('client_ip') ?? ($request->getServerParams()['REMOTE_ADDR'] ?? ''));
        $uid = (int)$user['id'];
        $email = (string)$user['email'];

        if ($action === 'change_password') {
            $current = (string)($body['current_password'] ?? '');
            $next = (string)($body['new_password'] ?? '');
            $confirm = (string)($body['confirm_password'] ?? '');

            if (!password_verify($current, (string)$user['password_hash'])) {
                return $this->render($request, $response->withStatus(422), flashError: 'Current password is incorrect.');
            }
            if (strlen($next) < 8 || $next !== $confirm) {
                return $this->render($request, $response->withStatus(422), flashError: 'New password must be at least 8 characters and match confirmation.');
            }

            $newVer = ((int)($user['token_version'] ?? 1)) + 1;
            $pdo->prepare('UPDATE users SET password_hash = :h, token_version = :v WHERE id = :id')
                ->execute([':h' => password_hash($next, PASSWORD_BCRYPT), ':v' => $newVer, ':id' => $uid]);

            if ($this->container->has(RbacManager::class)) {
                $this->container->get(RbacManager::class)->invalidateCache();
            }
            $audit?->record('auth.password_changed', 'HIGH', $email, $ip ?: null, 'core', 'user#' . $uid);

            // Re-issue fresh cookie for this session so current user stays signed in while other devices are revoked
            $config = (array)$this->container->get('config');
            $secret = (string)($config['admin']['auth_secret'] ?? $config['security']['csrf_secret'] ?? AdminToken::DEFAULT_INSECURE_SECRET);
            $ttl = (int)($config['security']['admin_ttl'] ?? 28800);
            $cookie = AdminToken::issue($email, $secret, $ttl, tokenVersion: $newVer);
            $secureFlag = (bool)($config['security']['cookie_secure'] ?? false) ? '; Secure' : '';

            $res = $this->render($request, $response, flashSuccess: 'Password updated. All other active sessions have been revoked.');
            return $res->withHeader('Set-Cookie', "lite_admin_auth={$cookie}; Path=/admin; Max-Age={$ttl}; HttpOnly; SameSite=Lax{$secureFlag}");
        }

        if ($action === 'enable_2fa') {
            $pendingSecret = preg_replace('/[^A-Z2-7]/', '', strtoupper(trim((string)($body['pending_secret'] ?? '')))) ?? '';
            $otpCode = trim((string)($body['otp_code'] ?? ''));

            if (strlen($pendingSecret) < 16 || !(new TotpAuthenticator())->verify($pendingSecret, $otpCode)) {
                return $this->render($request, $response->withStatus(422), flashError: 'Invalid 6-digit authenticator code. Please verify and try again.');
            }

            /** @var SecretVault $vault */
            $vault = $this->container->get(SecretVault::class);
            $encrypted = $vault->encrypt($pendingSecret, 'user_2fa', 'u_' . $uid);
            $pdo->prepare('UPDATE users SET totp_secret = :s WHERE id = :id')->execute([':s' => $encrypted, ':id' => $uid]);
            $audit?->record('auth.2fa_enabled', 'HIGH', $email, $ip ?: null, 'core', 'user#' . $uid);

            return $this->render($request, $response, flashSuccess: 'TOTP 2FA has been enabled for your account.');
        }

        if ($action === 'disable_2fa') {
            $current = (string)($body['current_password'] ?? '');
            if (!password_verify($current, (string)$user['password_hash'])) {
                return $this->render($request, $response->withStatus(422), flashError: 'Current password is required to disable 2FA.');
            }
            $pdo->prepare('UPDATE users SET totp_secret = NULL WHERE id = :id')->execute([':id' => $uid]);
            $audit?->record('auth.2fa_disabled', 'HIGH', $email, $ip ?: null, 'core', 'user#' . $uid);

            return $this->render($request, $response, flashSuccess: 'TOTP 2FA has been disabled.');
        }

        return $this->render($request, $response);
    }

    private function render(Request $request, Response $response, ?string $flashSuccess = null, ?string $flashError = null): Response
    {
        /** @var AdminDashboard $admin */
        $admin = $this->container->get(AdminDashboard::class);
        $csrf = htmlspecialchars((string)$request->getAttribute('csrf_token', ''), ENT_QUOTES, 'UTF-8');
        $e = static fn(string $v): string => htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        $user = $this->resolveCurrentUser($request);
        $email = $user ? (string)$user['email'] : (string)$request->getAttribute('admin_user', 'admin');
        $has2fa = $user !== null && trim((string)($user['totp_secret'] ?? '')) !== '';

        $totp = new TotpAuthenticator();
        $pendingSecret = $totp->generateSecret();
        $issuer = (string)($this->container->get('config')['name'] ?? 'LitePlatform');
        $otpUri = $totp->provisioningUri($issuer, $email, $pendingSecret);

        $twoFaBlock = $has2fa
            ? <<<HTML
<p style="margin-bottom:0.85rem;"><span class="badge badge-success">2FA Enabled</span> Your account requires a 6-digit TOTP code on every login.</p>
<form method="POST" action="/admin/profile">
    <input type="hidden" name="_csrf" value="{$csrf}">
    <input type="hidden" name="action" value="disable_2fa">
    <div class="form-group"><label>Confirm Current Password to Disable 2FA</label><input type="password" name="current_password" class="form-control" required></div>
    <button type="submit" class="btn btn-danger btn-sm">Disable 2FA</button>
</form>
HTML
            : <<<HTML
<p style="color:var(--text-muted);font-size:0.85rem;margin-bottom:0.75rem;">Add this secret key to Google Authenticator, Authy, or Bitwarden, then enter the 6-digit code to activate:</p>
<div style="background:var(--bg-main);border:1px solid var(--border);border-radius:8px;padding:0.75rem;margin-bottom:0.85rem;font-family:monospace;font-size:0.85rem;word-break:break-all;">
    <div><strong>Secret Key:</strong> {$e($pendingSecret)}</div>
    <div style="margin-top:0.35rem;font-size:0.74rem;color:var(--text-muted);">{$e($otpUri)}</div>
</div>
<form method="POST" action="/admin/profile">
    <input type="hidden" name="_csrf" value="{$csrf}">
    <input type="hidden" name="action" value="enable_2fa">
    <input type="hidden" name="pending_secret" value="{$e($pendingSecret)}">
    <div class="form-group"><label>6-Digit Verification Code</label><input type="text" name="otp_code" class="form-control" placeholder="123456" required></div>
    <button type="submit" class="btn btn-primary btn-sm">Verify &amp; Enable 2FA</button>
</form>
HTML;

        $content = <<<HTML
<div class="page-header">
    <div>
        <h1>My Account &amp; 2FA Security</h1>
        <p style="color:var(--text-muted);font-size:0.85rem;">Signed in as <strong>{$e($email)}</strong></p>
    </div>
</div>
<div style="display:grid;grid-template-columns:repeat(auto-fit, minmax(340px, 1fr));gap:1.25rem;">
    <div class="card">
        <div class="section-card-title">Change Password</div>
        <form method="POST" action="/admin/profile">
            <input type="hidden" name="_csrf" value="{$csrf}">
            <input type="hidden" name="action" value="change_password">
            <div class="form-group"><label>Current Password</label><input type="password" name="current_password" class="form-control" required></div>
            <div class="form-group"><label>New Password (min 8 chars)</label><input type="password" name="new_password" class="form-control" minlength="8" required></div>
            <div class="form-group"><label>Confirm New Password</label><input type="password" name="confirm_password" class="form-control" minlength="8" required></div>
            <button type="submit" class="btn btn-primary">Update Password &amp; Revoke Other Devices</button>
        </form>
    </div>
    <div class="card">
        <div class="section-card-title">Two-Factor Authentication (TOTP RFC 6238)</div>
        {$twoFaBlock}
    </div>
</div>
HTML;

        $html = $admin->getHtmlRenderer()->layout('My Account & 2FA', $content, 'profile', flashSuccess: $flashSuccess, flashError: $flashError);
        $response->getBody()->write($html);
        return $response->withHeader('Content-Type', 'text/html; charset=UTF-8');
    }

    /** @return array<string, mixed>|null */
    private function resolveCurrentUser(Request $request): ?array
    {
        if (!$this->container->has(PDO::class)) {
            return null;
        }
        $actor = (string)$request->getAttribute('admin_user', '');
        if ($actor === '') {
            return null;
        }
        $stmt = $this->container->get(PDO::class)->prepare(
            'SELECT id, name, email, password_hash, COALESCE(token_version, 1) AS token_version, totp_secret FROM users WHERE LOWER(email) = LOWER(:a) OR LOWER(name) = LOWER(:a2) LIMIT 1'
        );
        $stmt->execute([':a' => $actor, ':a2' => $actor]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }
}
