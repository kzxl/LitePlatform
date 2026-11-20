<?php

declare(strict_types=1);

namespace Lite\Framework\Console;

use Lite\Framework\Core\Kernel;
use Lite\Framework\Security\AdminToken;

/**
 * Shared Hosting & Production Security Preflight Doctor (`php bin/lite doctor [--strict]`).
 * Audits PHP runtime, extensions, writable storage, .htaccess, and production security posture.
 */
final class DoctorCommand
{
    /**
     * @return array{ok: bool, passed: int, warnings: int, failures: int, checks: list<array{status: string, name: string, detail: string}>}
     */
    public function diagnose(string $baseDir, bool $strictProduction = false): array
    {
        Kernel::loadEnv($baseDir . '/.env');
        $config = is_file($baseDir . '/config/app.php') ? (array)(require $baseDir . '/config/app.php') : [];
        $env = (string)($config['env'] ?? 'development');
        $checkProd = $strictProduction || $env === 'production';

        $checks = [];
        $add = static function (string $status, string $name, string $detail) use (&$checks): void {
            $checks[] = ['status' => $status, 'name' => $name, 'detail' => $detail];
        };

        // 1. PHP Version >= 8.2
        $add(
            version_compare(PHP_VERSION, '8.2.0', '>=') ? 'PASS' : 'FAIL',
            'PHP Version',
            PHP_VERSION . ' (requires >= 8.2.0)'
        );

        // 2. Core Extensions
        foreach (['pdo', 'fileinfo', 'mbstring', 'json'] as $ext) {
            $add(
                extension_loaded($ext) ? 'PASS' : 'FAIL',
                "Extension: {$ext}",
                extension_loaded($ext) ? 'Loaded' : 'Missing required extension'
            );
        }

        // 3. Recommended Shared Hosting Extensions (PDO MySQL, GD WebP, OPcache)
        $add(
            extension_loaded('pdo_mysql') ? 'PASS' : 'WARN',
            'Extension: pdo_mysql',
            extension_loaded('pdo_mysql') ? 'Loaded (MySQL/MariaDB ready)' : 'Not loaded (required on MySQL Shared Hosting)'
        );
        $add(
            (extension_loaded('gd') && function_exists('imagewebp')) ? 'PASS' : 'WARN',
            'Extension: gd + WebP',
            (extension_loaded('gd') && function_exists('imagewebp')) ? 'Loaded (WebP conversion enabled)' : 'GD/WebP unavailable (ImageOptimizer will copy originals)'
        );

        // 4. Writable directories
        foreach (['storage/cache', 'storage/logs'] as $rel) {
            $dir = $baseDir . '/' . $rel;
            if (!is_dir($dir)) {
                @mkdir($dir, 0755, true);
            }
            $writable = is_dir($dir) && is_writable($dir);
            $add($writable ? 'PASS' : 'FAIL', "Writable: {$rel}", $writable ? 'Writable' : 'Not writable');
        }

        // 5. Shared Hosting .htaccess
        $hasHtaccess = is_file($baseDir . '/public/.htaccess');
        $add($hasHtaccess ? 'PASS' : 'FAIL', 'Apache/LiteSpeed .htaccess', $hasHtaccess ? 'public/.htaccess present' : 'Missing public/.htaccess');

        // 6. Security Posture
        $debug = (bool)($config['debug'] ?? true);
        if ($checkProd && $debug) {
            $add('FAIL', 'APP_DEBUG', 'APP_DEBUG is true in production/strict mode');
        } else {
            $add('PASS', 'APP_DEBUG', $debug ? 'true (development)' : 'false (hardened)');
        }

        $csrfEnabled = (bool)($config['security']['csrf_enabled'] ?? true);
        $add($csrfEnabled ? 'PASS' : 'FAIL', 'CSRF Protection', $csrfEnabled ? 'Enabled (CsrfGuard)' : 'Disabled');

        $csrfSecret = (string)($config['security']['csrf_secret'] ?? '');
        $jwtSecret = (string)($config['api']['jwt_secret'] ?? '');
        $weakSecret = strlen($csrfSecret) < 32
            || strlen($jwtSecret) < 32
            || $csrfSecret === 'csrf-secret-key-for-stateless-tokens'
            || $csrfSecret === AdminToken::DEFAULT_INSECURE_SECRET
            || str_starts_with($jwtSecret, 'default-');

        if ($checkProd && $weakSecret) {
            $add('FAIL', 'Production Secrets', 'Default or <32-char APP_KEY/CSRF_SECRET/JWT_SECRET detected');
        } elseif ($weakSecret) {
            $add('WARN', 'Production Secrets', 'Using local dev secret defaults (rotate before deploying)');
        } else {
            $add('PASS', 'Production Secrets', 'Strong >= 32-char secrets configured');
        }

        $cors = (array)($config['api']['cors']['allowed_origins'] ?? []);
        if ($checkProd && in_array('*', $cors, true)) {
            $add('FAIL', 'CORS Policy', 'Wildcard * allowed_origins active in production');
        } else {
            $add('PASS', 'CORS Policy', implode(', ', $cors) ?: 'restricted');
        }

        $passed = 0;
        $warnings = 0;
        $failures = 0;
        foreach ($checks as $c) {
            match ($c['status']) {
                'PASS' => $passed++,
                'WARN' => $warnings++,
                default => $failures++,
            };
        }

        return [
            'ok'       => $failures === 0,
            'passed'   => $passed,
            'warnings' => $warnings,
            'failures' => $failures,
            'checks'   => $checks,
        ];
    }
}
