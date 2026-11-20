<?php

declare(strict_types=1);

namespace LiteORM\Tests\Unit;

use LiteORM\Migration\MigrationRunner;
use PDO;
use PHPUnit\Framework\TestCase;

class MigrationRunnerTest extends TestCase
{
    private PDO $pdo;
    private string $tempDir;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $this->tempDir = sys_get_temp_dir() . '/lite_orm_migrations_' . uniqid();
        mkdir($this->tempDir, 0777, true);
    }

    protected function tearDown(): void
    {
        // Cleanup temp files
        $files = glob($this->tempDir . '/*.php');
        if ($files) {
            foreach ($files as $file) {
                unlink($file);
            }
        }
        if (is_dir($this->tempDir)) {
            rmdir($this->tempDir);
        }
    }

    public function testInitTableAndCreateMigration(): void
    {
        $runner = new MigrationRunner($this->pdo);

        $stmt = $this->pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='lite_migrations'");
        $this->assertEquals('lite_migrations', $stmt->fetchColumn());

        $filePath = $runner->create('create_products_table', $this->tempDir);
        $this->assertFileExists($filePath);
        $this->assertStringContainsString('AbstractMigration', file_get_contents($filePath));
    }

    public function testMigrateAndRollbackFlow(): void
    {
        $runner = new MigrationRunner($this->pdo);

        // 1. Create Migration 1: create items table
        $mig1Path = $this->tempDir . '/2026_01_01_000000_create_items.php';
        file_put_contents($mig1Path, <<<'PHP'
<?php
use LiteORM\Migration\AbstractMigration;

return new class extends AbstractMigration {
    public function up(): void {
        $this->execute("CREATE TABLE items (id INTEGER PRIMARY KEY, title VARCHAR(100))");
    }
    public function down(): void {
        $this->execute("DROP TABLE IF EXISTS items");
    }
};
PHP);

        // 2. Create Migration 2: add price to items
        $mig2Path = $this->tempDir . '/2026_01_01_000001_add_price_to_items.php';
        file_put_contents($mig2Path, <<<'PHP'
<?php
use LiteORM\Migration\AbstractMigration;

return new class extends AbstractMigration {
    public function up(): void {
        $this->execute("ALTER TABLE items ADD COLUMN price REAL DEFAULT 0.0");
    }
    public function down(): void {
        // SQLite does not support DROP COLUMN in older versions, but dummy execute for test
        $this->execute("SELECT 1");
    }
};
PHP);

        // 3. Status before migrate
        $statuses = $runner->status($this->tempDir);
        $this->assertCount(2, $statuses);
        $this->assertFalse($statuses[0]['ran']);
        $this->assertFalse($statuses[1]['ran']);

        // 4. Run migrations
        $migrated = $runner->migrate($this->tempDir);
        $this->assertCount(2, $migrated);
        $this->assertEquals(1, $runner->getLatestBatch());

        // Verify table and column exist
        $this->pdo->exec("INSERT INTO items (id, title, price) VALUES (1, 'Widget', 19.99)");
        $row = $this->pdo->query("SELECT * FROM items WHERE id = 1")->fetch(PDO::FETCH_ASSOC);
        $this->assertEquals('Widget', $row['title']);
        $this->assertEquals(19.99, (float)$row['price']);

        // 5. Status after migrate
        $statuses = $runner->status($this->tempDir);
        $this->assertTrue($statuses[0]['ran']);
        $this->assertTrue($statuses[1]['ran']);
        $this->assertEquals(1, $statuses[0]['batch']);

        // 6. Rollback
        $rolledBack = $runner->rollback($this->tempDir, 1);
        $this->assertCount(2, $rolledBack);
        $this->assertEquals(0, $runner->getLatestBatch());
    }
}
