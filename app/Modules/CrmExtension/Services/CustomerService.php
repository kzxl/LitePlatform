<?php

declare(strict_types=1);

namespace App\Modules\CrmExtension\Services;

use PDO;

/**
 * Customer CRM service supporting idempotent phone-keyed upserts from web inquiries or POS.
 */
final class CustomerService
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function upsertByPhone(string $name, string $phone, ?string $chatHandle = null, ?string $notes = null): int
    {
        $now = date('Y-m-d H:i:s');
        $find = $this->pdo->prepare('SELECT id FROM retail_customers WHERE phone = :phone LIMIT 1');
        $find->execute([':phone' => $phone]);
        $existingId = $find->fetchColumn();

        if ($existingId !== false) {
            $this->pdo->prepare(
                'UPDATE retail_customers SET name = :name, chat_handle = COALESCE(NULLIF(:chat, \'\'), chat_handle), updated_at = :u WHERE id = :id'
            )->execute([
                ':name' => mb_substr($name, 0, 150),
                ':chat' => mb_substr((string)($chatHandle ?? ''), 0, 120),
                ':u'    => $now,
                ':id'   => (int)$existingId,
            ]);
            return (int)$existingId;
        }

        $this->pdo->prepare(
            'INSERT INTO retail_customers (name, phone, chat_handle, notes, created_at) VALUES (:name, :phone, :chat, :notes, :c)'
        )->execute([
            ':name'  => mb_substr($name, 0, 150),
            ':phone' => mb_substr($phone, 0, 40),
            ':chat'  => $chatHandle !== null ? mb_substr($chatHandle, 0, 120) : null,
            ':notes' => $notes !== null ? mb_substr($notes, 0, 500) : null,
            ':c'     => $now,
        ]);

        return (int)$this->pdo->lastInsertId();
    }
}
