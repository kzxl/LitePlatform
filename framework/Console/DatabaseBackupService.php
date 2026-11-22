<?php

declare(strict_types=1);

namespace Lite\Framework\Console;

use PDO;

/**
 * Pure-PHP Shared-Hosting Database Backup Service (zero exec() / mysqldump dependency).
 * Generates portable SQL dumps across SQLite, MySQL/MariaDB, and PostgreSQL,
 * compresses with gzip (.sql.gz) when zlib is available, and enforces rolling retention.
 */
final class DatabaseBackupService
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly string $backupDir
    ) {
    }

    /**
     * Dump all application tables into a timestamped backup file and prune old archives.
     *
     * @return array{path: string, filename: string, tables: int, rows: int, bytes: int, compressed: bool, pruned: int}
     */
    public function createBackup(int $retentionCount = 7): array
    {
        if (!is_dir($this->backupDir)) {
            mkdir($this->backupDir, 0755, true);
        }

        $driver = (string)$this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        $tables = $this->listTables($driver);

        $lines = [
            '-- LitePlatform Pure-PHP Database Backup',
            '-- Driver: ' . $driver,
            '-- Generated (UTC): ' . gmdate('Y-m-d\TH:i:s\Z'),
            '',
        ];
        if ($driver === 'mysql') {
            $lines[] = 'SET NAMES utf8mb4;';
            $lines[] = 'SET FOREIGN_KEY_CHECKS = 0;';
            $lines[] = '';
        }

        $totalRows = 0;

        foreach ($tables as $table) {
            if (!preg_match('/^[a-zA-Z0-9_]+$/', $table)) {
                continue;
            }

            $ddl = $this->getTableCreateSql($driver, $table);
            if ($ddl !== null) {
                $lines[] = "-- Table: {$table}";
                $lines[] = rtrim($ddl, ';') . ';';
            }

            $qTable = $driver === 'pgsql' ? "\"{$table}\"" : "`{$table}`";
            $stmt = $this->pdo->query("SELECT * FROM {$qTable}");
            if ($stmt !== false) {
                while (($row = $stmt->fetch(PDO::FETCH_ASSOC)) !== false) {
                    $cols = array_keys($row);
                    $qCols = implode(', ', array_map(
                        static fn(string $c): string => $driver === 'pgsql' ? "\"{$c}\"" : "`{$c}`",
                        $cols
                    ));
                    $vals = implode(', ', array_map(
                        fn(mixed $v): string => $this->quoteValue($v),
                        array_values($row)
                    ));
                    $lines[] = "INSERT INTO {$qTable} ({$qCols}) VALUES ({$vals});";
                    $totalRows++;
                }
            }
            $lines[] = '';
        }

        if ($driver === 'mysql') {
            $lines[] = 'SET FOREIGN_KEY_CHECKS = 1;';
        }

        $sql = implode("\n", $lines) . "\n";
        $stamp = gmdate('Ymd_His') . '_' . substr(bin2hex(random_bytes(2)), 0, 4);
        $useGzip = function_exists('gzencode');

        $filename = $useGzip ? "backup_{$stamp}.sql.gz" : "backup_{$stamp}.sql";
        $fullPath = rtrim($this->backupDir, '/\\') . '/' . $filename;
        $payload = $useGzip ? (gzencode($sql, 6) ?: $sql) : $sql;

        file_put_contents($fullPath, $payload, LOCK_EX);
        $pruned = $this->pruneOldBackups($retentionCount);

        return [
            'path'       => $fullPath,
            'filename'   => $filename,
            'tables'     => count($tables),
            'rows'       => $totalRows,
            'bytes'      => strlen($payload),
            'compressed' => $useGzip,
            'pruned'     => $pruned,
        ];
    }

    /**
     * List existing backup archives sorted newest first.
     *
     * @return list<array{filename: string, path: string, bytes: int, modified_at: string}>
     */
    public function listBackups(): array
    {
        if (!is_dir($this->backupDir)) {
            return [];
        }

        $files = glob(rtrim($this->backupDir, '/\\') . '/backup_*.sql*') ?: [];
        usort($files, static fn(string $a, string $b): int => (int)filemtime($b) <=> (int)filemtime($a));

        $list = [];
        foreach ($files as $f) {
            $list[] = [
                'filename'    => basename($f),
                'path'        => $f,
                'bytes'       => (int)filesize($f),
                'modified_at' => gmdate('Y-m-d\TH:i:s\Z', (int)filemtime($f)),
            ];
        }
        return $list;
    }

    /**
     * Check whether a fresh backup exists within the last $maxAgeHours hours.
     */
    public function hasRecentBackup(int $maxAgeHours = 24): bool
    {
        $backups = $this->listBackups();
        if (empty($backups)) {
            return false;
        }
        $latestMtime = (int)filemtime($backups[0]['path']);
        return (time() - $latestMtime) < ($maxAgeHours * 3600);
    }

    private function pruneOldBackups(int $keep): int
    {
        $keep = max(1, $keep);
        $backups = $this->listBackups();
        $pruned = 0;
        foreach (array_slice($backups, $keep) as $old) {
            if (@unlink($old['path'])) {
                $pruned++;
            }
        }
        return $pruned;
    }

    /** @return list<string> */
    private function listTables(string $driver): array
    {
        $sql = match ($driver) {
            'sqlite' => "SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%' ORDER BY name ASC",
            'pgsql'  => "SELECT tablename FROM pg_tables WHERE schemaname = 'public' ORDER BY tablename ASC",
            default  => 'SHOW TABLES',
        };
        $stmt = $this->pdo->query($sql);
        return $stmt ? ($stmt->fetchAll(PDO::FETCH_COLUMN) ?: []) : [];
    }

    private function getTableCreateSql(string $driver, string $table): ?string
    {
        try {
            if ($driver === 'sqlite') {
                $stmt = $this->pdo->prepare("SELECT sql FROM sqlite_master WHERE type='table' AND name = :t");
                $stmt->execute([':t' => $table]);
                $ddl = $stmt->fetchColumn();
                return is_string($ddl) ? $ddl : null;
            }
            if ($driver === 'mysql') {
                $stmt = $this->pdo->query("SHOW CREATE TABLE `{$table}`");
                $row = $stmt ? $stmt->fetch(PDO::FETCH_NUM) : false;
                return is_array($row) && isset($row[1]) ? (string)$row[1] : null;
            }
        } catch (\Throwable) {
        }
        return null;
    }

    private function quoteValue(mixed $value): string
    {
        if ($value === null) {
            return 'NULL';
        }
        if (is_int($value) || is_float($value)) {
            return (string)$value;
        }
        return $this->pdo->quote((string)$value);
    }
}
