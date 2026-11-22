<?php

declare(strict_types=1);

namespace Tests;

use App\Controllers\AuthController;
use Lite\Framework\Console\ReleaseBuilder;
use Lite\Framework\Security\AdminToken;
use Lite\Framework\Security\CsrfGuard;
use Lite\Framework\Security\RbacManager;
use Lite\Framework\Security\SecretVault;
use Lite\Framework\Security\TotpAuthenticator;
use Lite\Framework\Testing\TestCase;
use PDO;

/**
 * Verifies the final 5 Production-Readiness controls:
 * 1. ReleaseBuilder bundles themes/, plugins.lock.json, storage/backups, and public/uploads/.htaccess
 * 2. AdminDashboard::beforeSave() blocks double-booked rental Orders directly on Admin form submit (422)
 * 3. TOTP 2FA enforced inside AuthController::processLogin()
 * 4. Self-service /admin/profile password change & /admin/forgot-password + /admin/reset-password recovery
 */
class ProductionReadinessTest extends TestCase
{
    public function testReleaseBuilderIncludesThemesLockfileAndUploadsHtaccess(): void
    {
        $baseDir = dirname(__DIR__);
        $builder = new ReleaseBuilder();
        $res = $builder->build($baseDir);
        $stage = $res['stage_dir'];

        $this->assertFileExists($stage . '/plugins.lock.json');
        $this->assertFileExists($stage . '/themes/editorial/theme.json');
        $this->assertFileExists($stage . '/themes/editorial/layouts/app.lite.php');
        $this->assertFileExists($stage . '/public/uploads/.htaccess');
        $this->assertDirectoryExists($stage . '/storage/backups');
    }

    public function testAdminOrderFormBlocksOverlappingRentalDatesViaBeforeSaveHook(): void
    {
        $container = $this->app->getContainer();
        /** @var PDO $pdo */
        $pdo = $container->get(PDO::class);

        $suffix = substr(uniqid(), -6);
        $itemId = 0;
        $custId = 0;
        $orderId = 0;

        try {
            $pdo->prepare("INSERT INTO retail_items (sku, name, slug, listing_type, price, status) VALUES (:sku, :n, :s, 'rent', 500000, 'active')")
                ->execute([':sku' => 'PR-' . $suffix, ':n' => 'Overlap Guard Item', ':s' => 'overlap-item-' . $suffix]);
            $itemId = (int)$pdo->lastInsertId();

            $pdo->prepare("INSERT INTO retail_customers (name, phone) VALUES ('Guard Customer', :p)")
                ->execute([':p' => '0944' . random_int(100000, 999999)]);
            $custId = (int)$pdo->lastInsertId();

            $pdo->prepare("INSERT INTO retail_orders (order_code, order_type, customer_id, item_id, start_date, end_date, subtotal, status) VALUES (:oc, 'rental', :cid, :iid, '2027-10-10', '2027-10-12', 500000, 'confirmed')")
                ->execute([':oc' => 'ORD-LOCK-' . $suffix, ':cid' => $custId, ':iid' => $itemId]);
            $orderId = (int)$pdo->lastInsertId();

            $adminCookie = $this->makeAdminCookie();
            $createPage = $this->app->handle(
                $this->factory->createServerRequest('GET', '/admin/order/create')
                    ->withCookieParams(['lite_admin_auth' => $adminCookie])
            );
            $this->assertStatus($createPage, 200);
            preg_match('/name="_csrf"\s+value="([^"]+)"/', (string)$createPage->getBody(), $m);
            $csrf = $m[1] ?? '';

            // Submitting overlapping rental order must fail with HTTP 422 and cite the conflicting order code!
            $conflictRes = $this->app->handle(
                $this->factory->createServerRequest('POST', '/admin/order/create')
                    ->withCookieParams([
                        'lite_admin_auth'      => $adminCookie,
                        CsrfGuard::COOKIE_NAME => $csrf,
                    ])
                    ->withParsedBody([
                        '_csrf'           => $csrf,
                        'orderCode'       => 'ORD-DUP-' . $suffix,
                        'orderType'       => 'rental',
                        'customerId'      => (string)$custId,
                        'itemId'          => (string)$itemId,
                        'startDate'       => '2027-10-11',
                        'endDate'         => '2027-10-13',
                        'subtotal'        => '500000',
                        'depositHeld'     => '300000',
                        'depositRefunded' => '0',
                        'status'          => 'confirmed',
                    ])
            );
            $this->assertStatus($conflictRes, 422);
            $this->assertStringContainsString('ORD-LOCK-' . $suffix, (string)$conflictRes->getBody());
        } finally {
            if ($orderId > 0) {
                $pdo->prepare('DELETE FROM retail_orders WHERE id = :id')->execute([':id' => $orderId]);
            }
            if ($custId > 0) {
                $pdo->prepare('DELETE FROM retail_customers WHERE id = :id')->execute([':id' => $custId]);
            }
            if ($itemId > 0) {
                $pdo->prepare('DELETE FROM retail_items WHERE id = :id')->execute([':id' => $itemId]);
            }
        }
    }

    public function testTotp2faLoginEnforcementAndPasswordResetFlow(): void
    {
        $container = $this->app->getContainer();
        /** @var PDO $pdo */
        $pdo = $container->get(PDO::class);
        /** @var SecretVault $vault */
        $vault = $container->get(SecretVault::class);
        /** @var RbacManager $rbac */
        $rbac = $container->get(RbacManager::class);

        $suffix = substr(uniqid(), -6);
        $email = "totp-{$suffix}@test.local";
        $totp = new TotpAuthenticator();
        $plainSecret = $totp->generateSecret();
        $uid = 0;

        try {
            $pdo->prepare("INSERT INTO users (name, email, role, password_hash, token_version, is_active) VALUES ('2FA Admin', :e, 'admin', :h, 1, 1)")
                ->execute([':e' => $email, ':h' => password_hash('OldPass123!', PASSWORD_BCRYPT)]);
            $uid = (int)$pdo->lastInsertId();

            $encSecret = $vault->encrypt($plainSecret, 'user_2fa', 'u_' . $uid);
            $pdo->prepare('UPDATE users SET totp_secret = :s WHERE id = :id')->execute([':s' => $encSecret, ':id' => $uid]);

            // Fetch login CSRF
            $loginGet = $this->app->handle($this->factory->createServerRequest('GET', '/admin/login'));
            preg_match('/name="_csrf"\s+value="([^"]+)"/', (string)$loginGet->getBody(), $m);
            $csrf = $m[1] ?? '';

            // 1. Login without 2FA code -> 401 rejected
            $fail2fa = $this->app->handle(
                $this->factory->createServerRequest('POST', '/admin/login')
                    ->withCookieParams([CsrfGuard::COOKIE_NAME => $csrf])
                    ->withParsedBody(['_csrf' => $csrf, 'username' => $email, 'password' => 'OldPass123!'])
            );
            $this->assertStatus($fail2fa, 401);
            $this->assertStringContainsString('2FA', (string)$fail2fa->getBody());

            // 2. Login with valid 6-digit TOTP code -> 302 success
            $validCode = $totp->getCode($plainSecret);
            $ok2fa = $this->app->handle(
                $this->factory->createServerRequest('POST', '/admin/login')
                    ->withCookieParams([CsrfGuard::COOKIE_NAME => $csrf])
                    ->withParsedBody(['_csrf' => $csrf, 'username' => $email, 'password' => 'OldPass123!', 'totp_code' => $validCode])
            );
            $this->assertStatus($ok2fa, 302);
            $this->assertEquals('/admin', $ok2fa->getHeaderLine('Location'));

            // 3. Forgot & Reset Password flow bumps token_version and updates hash
            $authCtrl = new AuthController($container);
            $resetToken = $authCtrl->issueResetToken($email, tokenVersion: 1);

            $resetGet = $this->app->handle(
                $this->factory->createServerRequest('GET', '/admin/reset-password')->withQueryParams(['token' => $resetToken])
            );
            $this->assertStatus($resetGet, 200);
            preg_match('/name="_csrf"\s+value="([^"]+)"/', (string)$resetGet->getBody(), $rm);
            $rCsrf = $rm[1] ?? '';

            $resetPost = $this->app->handle(
                $this->factory->createServerRequest('POST', '/admin/reset-password')
                    ->withCookieParams([CsrfGuard::COOKIE_NAME => $rCsrf])
                    ->withParsedBody([
                        '_csrf'            => $rCsrf,
                        'token'            => $resetToken,
                        'password'         => 'NewStrongPass99!',
                        'password_confirm' => 'NewStrongPass99!',
                    ])
            );
            $this->assertStatus($resetPost, 200);
            $this->assertStringContainsString('Password reset successfully', (string)$resetPost->getBody());

            // Verify token_version bumped & new password works with 2FA
            $ver = (int)$pdo->query("SELECT token_version FROM users WHERE id = {$uid}")->fetchColumn();
            $this->assertEquals(2, $ver);

            $relogin = $this->app->handle(
                $this->factory->createServerRequest('POST', '/admin/login')
                    ->withCookieParams([CsrfGuard::COOKIE_NAME => $csrf])
                    ->withParsedBody(['_csrf' => $csrf, 'username' => $email, 'password' => 'NewStrongPass99!', 'totp_code' => $totp->getCode($plainSecret)])
            );
            $this->assertStatus($relogin, 302);

            // 4. Self-service /admin/profile page loads cleanly
            $profCookie = $this->makeAdminCookie($email, tokenVersion: 2);
            $profRes = $this->app->handle(
                $this->factory->createServerRequest('GET', '/admin/profile')
                    ->withCookieParams(['lite_admin_auth' => $profCookie])
            );
            $this->assertStatus($profRes, 200);
            $this->assertStringContainsString('2FA Enabled', (string)$profRes->getBody());
        } finally {
            if ($uid > 0) {
                $pdo->prepare('DELETE FROM users WHERE id = :id')->execute([':id' => $uid]);
                $rbac->invalidateCache();
            }
        }
    }

    private function makeAdminCookie(string $username = 'admin', int $tokenVersion = 1): string
    {
        $config = (array)$this->app->getContainer()->get('config');
        $secret = (string)($config['admin']['auth_secret'] ?? $config['security']['csrf_secret'] ?? AdminToken::DEFAULT_INSECURE_SECRET);
        return AdminToken::issue($username, $secret, 3600, tokenVersion: $tokenVersion);
    }
}
