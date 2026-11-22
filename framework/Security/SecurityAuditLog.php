<?php

declare(strict_types=1);

namespace Lite\Framework\Security;

use Lite\Framework\Mail\TelegramNotifier;
use PDO;

/**
 * Tamper-Evident Hash-Chained Security Audit Trail.
 *
 * Each row stores HMAC-SHA256(prev_hash || canonical_payload, audit_key).
 * Modifying, deleting, or inserting any historical row breaks the chain and is caught by verifyChain().
 */
final class SecurityAuditLog
{
    public const GENESIS_HASH = '0000000000000000000000000000000000000000000000000000000000000000';

    private readonly string $hmacKey;

    public function __construct(
        private readonly PDO $pdo,
        string $secret,
        private readonly ?TelegramNotifier $telegram = null
    ) {
        $this->hmacKey = hash_hkdf('sha256', $secret, 32, 'liteplatform-security-audit-chain-v1');
    }

    /**
     * Append a cryptographically chained security event.
     *
     * @param array<string, mixed> $context
     */
    public function record(
        string $eventType,
        string $severity = 'INFO',
        string $actor = 'system',
        ?string $actorIp = null,
        string $moduleId = 'core',
        ?string $target = null,
        array $context = []
    ): int {
        $severity = strtoupper($severity);
        $occurredAt = gmdate('Y-m-d\TH:i:s\Z');
        $contextJson = json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}';

        $lastStmt = $this->pdo->query('SELECT entry_hash FROM security_audit_logs ORDER BY id DESC LIMIT 1');
        $prevHash = ($lastStmt && ($h = $lastStmt->fetchColumn()) !== false) ? (string)$h : self::GENESIS_HASH;

        $entryHash = $this->computeHash(
            $prevHash,
            $occurredAt,
            $severity,
            $eventType,
            $actor,
            $actorIp,
            $moduleId,
            $target,
            $contextJson
        );

        $stmt = $this->pdo->prepare('
            INSERT INTO security_audit_logs (
                occurred_at, severity, event_type, actor, actor_ip, module_id, target, context_json, prev_hash, entry_hash
            ) VALUES (
                :occurred_at, :severity, :event_type, :actor, :actor_ip, :module_id, :target, :context_json, :prev_hash, :entry_hash
            )
        ');
        $stmt->execute([
            ':occurred_at'  => $occurredAt,
            ':severity'     => $severity,
            ':event_type'   => mb_substr($eventType, 0, 80),
            ':actor'        => mb_substr($actor, 0, 160),
            ':actor_ip'     => $actorIp !== null ? mb_substr($actorIp, 0, 64) : null,
            ':module_id'    => mb_substr($moduleId, 0, 120),
            ':target'       => $target !== null ? mb_substr($target, 0, 255) : null,
            ':context_json' => $contextJson,
            ':prev_hash'    => $prevHash,
            ':entry_hash'   => $entryHash,
        ]);

        $id = (int)$this->pdo->lastInsertId();

        if ($severity === 'CRITICAL' && $this->telegram !== null && $this->telegram->isConfigured()) {
            $e = static fn(string $v): string => htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $this->telegram->send(
                "<b>[SECURITY CRITICAL]</b> {$e($eventType)}\nActor: {$e($actor)} ({$e($actorIp ?? 'n/a')})\nModule: {$e($moduleId)}\nTarget: {$e($target ?? '-')}"
            );
        }

        return $id;
    }

    /**
     * Walk the entire security_audit_logs chain and verify cryptographic integrity.
     *
     * @return array{ok: bool, checked: int, broken_at_id: ?int, reason: ?string}
     */
    public function verifyChain(): array
    {
        $stmt = $this->pdo->query('SELECT * FROM security_audit_logs ORDER BY id ASC');
        $rows = $stmt ? ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];

        $expectedPrev = self::GENESIS_HASH;
        $checked = 0;

        foreach ($rows as $row) {
            $checked++;
            $id = (int)$row['id'];

            if (!hash_equals($expectedPrev, (string)$row['prev_hash'])) {
                return [
                    'ok'           => false,
                    'checked'      => $checked,
                    'broken_at_id' => $id,
                    'reason'       => 'Chain prev_hash mismatch (row inserted, deleted, or reordered).',
                ];
            }

            $recomputed = $this->computeHash(
                (string)$row['prev_hash'],
                (string)$row['occurred_at'],
                (string)$row['severity'],
                (string)$row['event_type'],
                (string)$row['actor'],
                $row['actor_ip'] !== null ? (string)$row['actor_ip'] : null,
                (string)$row['module_id'],
                $row['target'] !== null ? (string)$row['target'] : null,
                (string)($row['context_json'] ?? '{}')
            );

            if (!hash_equals($recomputed, (string)$row['entry_hash'])) {
                return [
                    'ok'           => false,
                    'checked'      => $checked,
                    'broken_at_id' => $id,
                    'reason'       => 'Entry HMAC mismatch (row payload was tampered).',
                ];
            }

            $expectedPrev = (string)$row['entry_hash'];
        }

        return [
            'ok'           => true,
            'checked'      => $checked,
            'broken_at_id' => null,
            'reason'       => null,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function recent(int $limit = 50): array
    {
        $limit = max(1, min(500, $limit));
        $stmt = $this->pdo->query("SELECT * FROM security_audit_logs ORDER BY id DESC LIMIT {$limit}");
        return $stmt ? ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
    }

    private function computeHash(
        string $prevHash,
        string $occurredAt,
        string $severity,
        string $eventType,
        string $actor,
        ?string $actorIp,
        string $moduleId,
        ?string $target,
        string $contextJson
    ): string {
        $canonical = implode('|', [
            $prevHash,
            $occurredAt,
            $severity,
            $eventType,
            $actor,
            $actorIp ?? '',
            $moduleId,
            $target ?? '',
            $contextJson,
        ]);

        return hash_hmac('sha256', $canonical, $this->hmacKey);
    }
}
