<?php

declare(strict_types=1);

namespace Lite\Framework\Logging;

use Psr\Log\AbstractLogger;
use Stringable;

/**
 * Lightweight PSR-3 daily rotating file logger for Shared Hosting.
 * - Writes to storage/logs/app-YYYY-MM-DD.log with LOCK_EX.
 * - Redacts sensitive context fields (passwords, tokens, secrets, authorization headers).
 * - Automatically prunes log files older than $retentionDays to preserve disk quota.
 */
final class FileLogger extends AbstractLogger
{
    /** @var list<string> */
    private const SENSITIVE_KEYS = [
        'password', 'password_hash', 'pass', 'passwd', 'secret',
        'token', 'csrf', '_csrf', 'authorization', 'cookie', 'api_key', 'jwt',
    ];

    private bool $pruned = false;

    public function __construct(
        private readonly string $logDir,
        private readonly int $retentionDays = 14
    ) {
    }

    /**
     * @param mixed $level
     * @param array<mixed> $context
     */
    public function log($level, string|Stringable $message, array $context = []): void
    {
        if (!is_dir($this->logDir)) {
            @mkdir($this->logDir, 0755, true);
        }

        $date = date('Y-m-d');
        $timestamp = date('Y-m-d H:i:s');
        $levelStr = strtoupper((string)$level);

        $sanitizedContext = $this->redactContext($context);
        $contextJson = $sanitizedContext !== []
            ? ' ' . json_encode($sanitizedContext, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            : '';

        $line = sprintf("[%s] %s: %s%s\n", $timestamp, $levelStr, (string)$message, $contextJson);
        $file = rtrim($this->logDir, '/\\') . DIRECTORY_SEPARATOR . "app-{$date}.log";

        @file_put_contents($file, $line, FILE_APPEND | LOCK_EX);

        if (!$this->pruned && $this->retentionDays > 0) {
            $this->pruned = true;
            $this->pruneOldLogs();
        }
    }

    /**
     * @param array<mixed> $context
     * @return array<mixed>
     */
    private function redactContext(array $context): array
    {
        $clean = [];
        foreach ($context as $key => $value) {
            $lowerKey = strtolower((string)$key);
            $isSensitive = false;
            foreach (self::SENSITIVE_KEYS as $needle) {
                if (str_contains($lowerKey, $needle)) {
                    $isSensitive = true;
                    break;
                }
            }

            if ($isSensitive) {
                $clean[$key] = '[REDACTED]';
            } elseif (is_array($value)) {
                $clean[$key] = $this->redactContext($value);
            } elseif ($value instanceof \Throwable) {
                $clean[$key] = [
                    'class'   => $value::class,
                    'message' => $value->getMessage(),
                    'file'    => $value->getFile() . ':' . $value->getLine(),
                ];
            } else {
                $clean[$key] = $value;
            }
        }

        return $clean;
    }

    private function pruneOldLogs(): void
    {
        $cutoff = time() - ($this->retentionDays * 86400);
        $files = glob(rtrim($this->logDir, '/\\') . DIRECTORY_SEPARATOR . 'app-*.log') ?: [];

        foreach ($files as $file) {
            $mtime = @filemtime($file);
            if ($mtime !== false && $mtime < $cutoff) {
                @unlink($file);
            }
        }
    }
}
