<?php

declare(strict_types=1);

namespace App\Database\Seeders;

use PDO;

class DatabaseSeeder
{
    public function run(PDO $pdo): void
    {
        $count = (int)$pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();
        if ($count === 0) {
            $stmt = $pdo->prepare("
                INSERT INTO users (name, email, role, created_at)
                VALUES (:name, :email, :role, :created_at)
            ");

            $now = date('Y-m-d H:i:s');
            $stmt->execute([
                'name'       => 'System Administrator',
                'email'      => 'admin@liteplatform.io',
                'role'       => 'admin',
                'created_at' => $now,
            ]);

            $stmt->execute([
                'name'       => 'Standard User',
                'email'      => 'user@liteplatform.io',
                'role'       => 'user',
                'created_at' => $now,
            ]);
        }
    }
}
