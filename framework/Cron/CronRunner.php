<?php

declare(strict_types=1);

namespace Lite\Framework\Cron;

use DI\Container;
use Lite\Framework\Console\DatabaseBackupService;
use Lite\Framework\Core\Plugin\HookBus;
use Lite\Framework\Security\SecurityAuditLog;
use LiteCache\CacheInterface;
use PDO;

/**
 * Shared-Hosting Web Cron Orchestrator.
 * Executes periodic maintenance (daily auto-backup, queue processing, plugin `cron.tick` hooks)
 * under an atomic overlap lock without requiring VPS Supervisor daemons.
 */
final class CronRunner
{
    public function __construct(private readonly Container $container)
    {
    }

    /**
     * Derive deterministic Cron token when CRON_SECRET is not explicitly configured.
     */
    public static function resolveExpectedToken(array $config): string
    {
        $explicit = trim((string)($config['cron']['secret'] ?? $_ENV['CRON_SECRET'] ?? ''));
        if ($explicit !== '') {
            return $explicit;
        }

        $baseSecret = (string)(
            $config['security']['csrf_secret']
            ?? $_ENV['APP_KEY']
            ?? 'lite-platform-dev-secret-key-32!'
        );
        return hash_hmac('sha256', 'liteplatform-web-cron-v1', $baseSecret);
    }

    /**
     * Execute one cron cycle safely with overlap lock protection.
     *
     * @return array{ok: bool, skipped?: bool, reason?: string, ran_at: string, tasks: array<string, mixed>}
     */
    public function run(bool $forceBackup = false): array
    {
        $nowIso = gmdate('Y-m-d\TH:i:s\Z');
        $cache = $this->container->has(CacheInterface::class) ? $this->container->get(CacheInterface::class) : null;
        $lockKey = 'lite_cron_running_lock';

        if ($cache !== null && $cache->get($lockKey, false) === true) {
            return [
                'ok'     => true,
                'skipped'=> true,
                'reason' => 'Another cron tick is currently in progress.',
                'ran_at' => $nowIso,
                'tasks'  => [],
            ];
        }

        $cache?->set($lockKey, true, 55);
        $tasks = [];

        try {
            $config = $this->container->has('config') ? (array)$this->container->get('config') : [];
            $basePath = (string)($config['base_path'] ?? dirname(__DIR__, 2));

            // 1. Auto-Backup DB if overdue (>= 24h) or forced
            if ($this->container->has(PDO::class)) {
                $backupService = new DatabaseBackupService($this->container->get(PDO::class), $basePath . '/storage/backups');
                $retention = (int)($config['cron']['backup_retention'] ?? 7);
                if ($forceBackup || !$backupService->hasRecentBackup(24)) {
                    $bk = $backupService->createBackup($retention);
                    $tasks['backup'] = [
                        'created'    => true,
                        'filename'   => $bk['filename'],
                        'tables'     => $bk['tables'],
                        'rows'       => $bk['rows'],
                        'compressed' => $bk['compressed'],
                    ];
                    if ($this->container->has(SecurityAuditLog::class)) {
                        $this->container->get(SecurityAuditLog::class)->record(
                            eventType: 'backup.created',
                            severity: 'INFO',
                            actor: 'cron',
                            target: $bk['filename'],
                            context: ['tables' => $bk['tables'], 'rows' => $bk['rows']]
                        );
                    }
                } else {
                    $tasks['backup'] = ['created' => false, 'reason' => 'Recent backup (<24h) already exists.'];
                }
            }

            // 2. Dispatch fault-isolated `cron.tick` hook for all active Extensions
            if ($this->container->has(HookBus::class)) {
                /** @var HookBus $hooks */
                $hooks = $this->container->get(HookBus::class);
                $context = [
                    'now'     => $nowIso,
                    'today'   => date('Y-m-d'),
                    'results' => [],
                ];
                $hooks->doAction('cron.tick', $context);
                $tasks['hooks'] = [
                    'dispatched' => 'cron.tick',
                    'errors'     => count($hooks->getRecordedErrors()),
                ];
            }
        } finally {
            $cache?->delete($lockKey);
        }

        return [
            'ok'     => true,
            'ran_at' => $nowIso,
            'tasks'  => $tasks,
        ];
    }
}
