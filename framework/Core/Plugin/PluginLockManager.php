<?php

declare(strict_types=1);

namespace Lite\Framework\Core\Plugin;

/**
 * Supply-Chain Integrity Lockfile Manager (`plugins.lock.json`).
 * Computes and verifies deterministic SHA-256 checksums across all plugin directories
 * to detect unauthorized file tampering on Shared Hosting servers.
 */
final class PluginLockManager
{
    public function __construct(
        private readonly string $basePath,
        private readonly ?PluginStaticScanner $scanner = null
    ) {
    }

    public function getLockFilePath(): string
    {
        return rtrim($this->basePath, '/\\') . '/plugins.lock.json';
    }

    /**
     * Scan all modules under app/Modules/* and write plugins.lock.json.
     *
     * @return array<string, array{sha256: string, files: int, audit_ok: bool, locked_at: string}>
     */
    public function generateLock(): array
    {
        $scanner = $this->scanner ?? new PluginStaticScanner();
        $dirs = glob(rtrim($this->basePath, '/\\') . '/app/Modules/*', GLOB_ONLYDIR) ?: [];
        sort($dirs);

        $lock = [];
        $now = gmdate('Y-m-d\TH:i:s\Z');

        foreach ($dirs as $dir) {
            $name = basename($dir);
            $scan = $scanner->scanDirectory($dir);
            $lock[$name] = [
                'sha256'    => $scan['checksum_sha256'],
                'files'     => $scan['files_scanned'],
                'audit_ok'  => $scan['ok'],
                'locked_at' => $now,
            ];
        }

        file_put_contents(
            $this->getLockFilePath(),
            json_encode(['schema' => 'les-lock-v1', 'plugins' => $lock], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n"
        );

        return $lock;
    }

    /**
     * Verify all installed modules against plugins.lock.json.
     *
     * @return array{
     *   has_lockfile: bool,
     *   ok: bool,
     *   entries: array<string, array{status: string, expected: ?string, actual: string}>
     * }
     */
    public function verifyLock(): array
    {
        $path = $this->getLockFilePath();
        if (!file_exists($path)) {
            return ['has_lockfile' => false, 'ok' => true, 'entries' => []];
        }

        $decoded = json_decode((string)file_get_contents($path), true);
        $expectedMap = is_array($decoded['plugins'] ?? null) ? $decoded['plugins'] : [];

        $scanner = $this->scanner ?? new PluginStaticScanner();
        $dirs = glob(rtrim($this->basePath, '/\\') . '/app/Modules/*', GLOB_ONLYDIR) ?: [];
        sort($dirs);

        $entries = [];
        $allOk = true;

        foreach ($dirs as $dir) {
            $name = basename($dir);
            $scan = $scanner->scanDirectory($dir);
            $actual = $scan['checksum_sha256'];
            $expected = isset($expectedMap[$name]['sha256']) ? (string)$expectedMap[$name]['sha256'] : null;

            if ($expected === null) {
                $status = 'UNLOCKED';
                $allOk = false;
            } elseif (hash_equals($expected, $actual)) {
                $status = 'VERIFIED';
            } else {
                $status = 'TAMPERED';
                $allOk = false;
            }

            $entries[$name] = [
                'status'   => $status,
                'expected' => $expected,
                'actual'   => $actual,
            ];
        }

        return [
            'has_lockfile' => true,
            'ok'           => $allOk,
            'entries'      => $entries,
        ];
    }
}
