<?php

declare(strict_types=1);

namespace Lite\Framework\Console;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ZipArchive;

/**
 * Packages a lean, production-ready Shared Hosting release under dist/release.
 * - Excludes dev-only code (framework/Dev, framework/Testing, framework/Generators, tests/).
 * - Excludes dev-only vendor packages (phpunit, sebastian, nikic/php-parser, etc.).
 * - Smartly bundles ONLY the packages/* required by active providers in config/app.php.
 */
final class ReleaseBuilder
{
    /** @var array<string, list<string>> */
    private const PROVIDER_PACKAGE_MAP = [
        'DatabaseProvider' => ['LiteORM'],
        'CacheProvider'    => ['LiteCache'],
        'ViewProvider'     => ['LiteView'],
        'SeoProvider'      => ['LiteCache', 'LiteUtil'],
        'ApiProvider'      => ['LiteApi', 'LiteValidate'],
        'AuditProvider'    => ['LiteAudit', 'LiteORM'],
        'AdminProvider'    => ['LiteAdmin', 'LiteORM', 'LiteValidate', 'LiteExport', 'LiteAudit'],
        'QueueProvider'    => ['LiteQueue'],
        'InertiaProvider'  => ['LiteInertia'],
    ];

    /**
     * @return array{stage_dir: string, archive: ?string, files: int, bytes: int, packages: list<string>}
     */
    public function build(string $baseDir, bool $includeAllPackages = false): array
    {
        $distDir = $baseDir . '/dist';
        $stageDir = $distDir . '/release';

        $this->cleanDir($stageDir);
        if (!is_dir($stageDir)) {
            mkdir($stageDir, 0755, true);
        }

        $activePackages = $includeAllPackages
            ? $this->discoverAllPackages($baseDir)
            : $this->resolveActivePackages($baseDir);

        $includePaths = [
            'app',
            'config',
            'database',
            'framework',
            'lang',
            'public',
            'templates',
            'themes',
            'vendor',
            'bin/lite',
            'plugins.lock.json',
            '.env.example',
            '.htaccess.shared-root.example',
            'composer.json',
            'composer.lock',
        ];

        $excludeRegexes = [
            '#(^|/)(\.git|tests|Tests)(/|$)#',
            '#^framework/(Dev|Testing|Generators)(/|$)#',
            '#^vendor/(phpunit|sebastian|phar-io|theseer|myclabs|staabm|nikic/php-parser)(/|$)#',
            '#^vendor/bin(/|$)#',
            '#^public/uploads/(?!\.htaccess$)#',
            '#\.md$#i',
        ];

        $fileCount = 0;
        $totalBytes = 0;

        foreach ($includePaths as $rel) {
            [$c, $b] = $this->copyPath($baseDir, $stageDir, $rel, $excludeRegexes);
            $fileCount += $c;
            $totalBytes += $b;
        }

        foreach ($activePackages as $pkg) {
            [$c, $b] = $this->copyPath($baseDir, $stageDir, 'packages/' . $pkg, $excludeRegexes);
            $fileCount += $c;
            $totalBytes += $b;
        }

        foreach (['storage/cache/views', 'storage/cache/data', 'storage/logs', 'storage/uploads', 'storage/backups', 'public/uploads'] as $dir) {
            if (!is_dir($stageDir . '/' . $dir)) {
                mkdir($stageDir . '/' . $dir, 0755, true);
            }
        }

        $archivePath = null;
        if (class_exists(ZipArchive::class)) {
            $zipFile = $distDir . '/liteplatform-release.zip';
            @unlink($zipFile);
            $zip = new ZipArchive();
            if ($zip->open($zipFile, ZipArchive::CREATE | ZipArchive::OVERWRITE) === true) {
                $it = new RecursiveIteratorIterator(
                    new RecursiveDirectoryIterator($stageDir, FilesystemIterator::SKIP_DOTS)
                );
                foreach ($it as $item) {
                    if ($item->isFile()) {
                        $localName = str_replace('\\', '/', substr($item->getPathname(), strlen($stageDir) + 1));
                        $zip->addFile($item->getPathname(), $localName);
                    }
                }
                $zip->close();
                $archivePath = $zipFile;
            }
        }

        return [
            'stage_dir' => $stageDir,
            'archive'   => $archivePath,
            'files'     => $fileCount,
            'bytes'     => $totalBytes,
            'packages'  => $activePackages,
        ];
    }

    /**
     * @return list<string>
     */
    public function resolveActivePackages(string $baseDir): array
    {
        $configFile = $baseDir . '/config/app.php';
        $packages = ['LiteUtil'];

        if (is_file($configFile)) {
            $cfg = require $configFile;
            foreach ((array)($cfg['providers'] ?? []) as $providerClass) {
                $shortName = basename(str_replace('\\', '/', (string)$providerClass));
                foreach (self::PROVIDER_PACKAGE_MAP[$shortName] ?? [] as $pkg) {
                    $packages[] = $pkg;
                }
            }
        }

        $unique = array_values(array_unique($packages));
        sort($unique);

        return array_values(array_filter(
            $unique,
            static fn(string $pkg): bool => is_dir($baseDir . '/packages/' . $pkg)
        ));
    }

    /**
     * @return list<string>
     */
    private function discoverAllPackages(string $baseDir): array
    {
        $pkgDir = $baseDir . '/packages';
        if (!is_dir($pkgDir)) {
            return [];
        }
        $dirs = glob($pkgDir . '/*', GLOB_ONLYDIR) ?: [];
        $names = array_map('basename', $dirs);
        sort($names);

        return $names;
    }

    /**
     * @param list<string> $excludeRegexes
     * @return array{0: int, 1: int}
     */
    private function copyPath(string $baseDir, string $stageDir, string $rel, array $excludeRegexes): array
    {
        $src = $baseDir . '/' . $rel;
        if (!file_exists($src)) {
            return [0, 0];
        }

        if (is_file($src)) {
            $dest = $stageDir . '/' . $rel;
            $parent = dirname($dest);
            if (!is_dir($parent)) {
                mkdir($parent, 0755, true);
            }
            copy($src, $dest);
            return [1, (int)filesize($dest)];
        }

        $count = 0;
        $bytes = 0;
        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($src, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($it as $item) {
            $subPath = $rel . '/' . str_replace('\\', '/', $it->getSubPathname());
            foreach ($excludeRegexes as $pattern) {
                if (preg_match($pattern, $subPath)) {
                    continue 2;
                }
            }

            $dest = $stageDir . '/' . $subPath;
            if ($item->isDir()) {
                if (!is_dir($dest)) {
                    mkdir($dest, 0755, true);
                }
            } else {
                $parent = dirname($dest);
                if (!is_dir($parent)) {
                    mkdir($parent, 0755, true);
                }
                copy($item->getPathname(), $dest);
                $count++;
                $bytes += (int)filesize($dest);
            }
        }

        return [$count, $bytes];
    }

    private function cleanDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($it as $item) {
            $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }
    }
}
