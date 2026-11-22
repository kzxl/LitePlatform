<?php

declare(strict_types=1);

namespace Tests;

use Lite\Framework\Console\DatabaseBackupService;
use Lite\Framework\Core\Plugin\BlueprintManager;
use Lite\Framework\Core\Plugin\HookBus;
use Lite\Framework\Cron\CronRunner;
use Lite\Framework\Security\AdminToken;
use Lite\Framework\Testing\TestCase;
use PDO;

/**
 * Verifies Multi-Domain Industry Blueprints, Pure-PHP Database Backups (.sql.gz + retention),
 * and Shared-Hosting HMAC Web Cron (/cron/run) with plugin `cron.tick` hook dispatch.
 */
class PlatformBlueprintsAndCronTest extends TestCase
{
    public function testBlueprintManagerSwitchesBusinessModesAndUpdatesRegistryAndSettings(): void
    {
        $container = $this->app->getContainer();
        /** @var PDO $pdo */
        $pdo = $container->get(PDO::class);
        $mgr = new BlueprintManager($pdo);

        $blueprints = $mgr->listBlueprints();
        $this->assertArrayHasKey('company-cms', $blueprints);
        $this->assertArrayHasKey('showroom-catalog', $blueprints);
        $this->assertArrayHasKey('rental-studio', $blueprints);
        $this->assertArrayHasKey('retail-commerce', $blueprints);

        try {
            $applied = $mgr->apply('showroom-catalog', 'phpunit');
            $this->assertEquals('showroom-catalog', $applied['blueprint']);

            $stmt = $pdo->prepare('SELECT state FROM plugin_registry WHERE plugin_id = :id');
            $stmt->execute([':id' => 'com.liteplatform.catalog']);
            $this->assertEquals('enabled', $stmt->fetchColumn());

            $stmt->execute([':id' => 'com.liteplatform.orders']);
            $this->assertEquals('disabled', $stmt->fetchColumn());

            $sStmt = $pdo->prepare("SELECT setting_value FROM plugin_settings WHERE plugin_id = 'com.liteplatform.catalog' AND setting_key = 'nav_label'");
            $sStmt->execute();
            $this->assertEquals('Bộ Sưu Tập', $sStmt->fetchColumn());
        } finally {
            // Restore full retail-commerce blueprint so subsequent tests see all extensions enabled
            $mgr->apply('retail-commerce', 'phpunit-cleanup');
        }
    }

    public function testDatabaseBackupServiceCreatesCompressedSqlAndPrunesRetention(): void
    {
        /** @var PDO $pdo */
        $pdo = $this->app->getContainer()->get(PDO::class);
        $tmpDir = sys_get_temp_dir() . '/lite_backup_test_' . uniqid();

        try {
            $service = new DatabaseBackupService($pdo, $tmpDir);
            $first = $service->createBackup(retentionCount: 2);
            $this->assertFileExists($first['path']);
            $this->assertGreaterThan(0, $first['tables']);
            $this->assertTrue($service->hasRecentBackup(24));

            $raw = (string)file_get_contents($first['path']);
            $sql = $first['compressed'] ? (string)gzdecode($raw) : $raw;
            $this->assertStringContainsString('LitePlatform Pure-PHP Database Backup', $sql);
            $this->assertStringContainsString('CREATE TABLE', $sql);

            // Create 2 more backups -> retention=2 should prune oldest file
            usleep(10000);
            $service->createBackup(retentionCount: 2);
            usleep(10000);
            $third = $service->createBackup(retentionCount: 2);
            $this->assertCount(2, $service->listBackups());
            $this->assertGreaterThanOrEqual(1, $third['pruned']);
        } finally {
            if (is_dir($tmpDir)) {
                foreach (glob($tmpDir . '/*') ?: [] as $f) {
                    @unlink($f);
                }
                @rmdir($tmpDir);
            }
        }
    }

    public function testWebCronEndpointRejectsInvalidTokenAndRunsWithValidHmacToken(): void
    {
        $container = $this->app->getContainer();
        $config = (array)$container->get('config');
        $basePath = (string)($config['base_path'] ?? dirname(__DIR__));
        $backupDir = $basePath . '/storage/backups';
        $beforeBackups = is_dir($backupDir) ? (glob($backupDir . '/backup_*.sql*') ?: []) : [];

        // 1. Missing or invalid token -> 403 Forbidden
        $badRes = $this->app->handle(
            $this->factory->createServerRequest('GET', '/cron/run')->withQueryParams(['token' => 'wrong-token'])
        );
        $this->assertStatus($badRes, 403);

        // 2. Valid HMAC token -> 200 JSON + dispatches cron.tick on HookBus
        $hookTicked = false;
        /** @var HookBus $hooks */
        $hooks = $container->get(HookBus::class);
        $hooks->addAction('cron.tick', static function (array $payload) use (&$hookTicked): void {
            if (!empty($payload['today'])) {
                $hookTicked = true;
            }
        });

        try {
            $token = CronRunner::resolveExpectedToken($config);
            $okRes = $this->app->handle(
                $this->factory->createServerRequest('GET', '/cron/run')->withQueryParams(['token' => $token])
            );
            $this->assertStatus($okRes, 200);
            $json = json_decode((string)$okRes->getBody(), true);
            $this->assertTrue($json['ok'] ?? false);
            $this->assertTrue($hookTicked, 'cron.tick hook should have fired.');
            $this->assertEquals('cron.tick', $json['tasks']['hooks']['dispatched'] ?? '');
        } finally {
            // Remove any backup archive created during this test run
            $afterBackups = is_dir($backupDir) ? (glob($backupDir . '/backup_*.sql*') ?: []) : [];
            foreach (array_diff($afterBackups, $beforeBackups) as $created) {
                @unlink($created);
            }
        }
    }

    public function testAdminSecurityCenterShowsBlueprintsAndBackupControls(): void
    {
        $config = (array)$this->app->getContainer()->get('config');
        $secret = (string)($config['admin']['auth_secret'] ?? $config['security']['csrf_secret'] ?? AdminToken::DEFAULT_INSECURE_SECRET);
        $cookie = AdminToken::issue('admin', $secret, 3600);

        $res = $this->app->handle(
            $this->factory->createServerRequest('GET', '/admin/security')
                ->withCookieParams(['lite_admin_auth' => $cookie])
        );
        $this->assertStatus($res, 200);
        $html = (string)$res->getBody();
        $this->assertStringContainsString('1-Click Industry Blueprints', $html);
        $this->assertStringContainsString('rental-studio', $html);
        $this->assertStringContainsString('Backup DB Now', $html);
    }
}
