<?php

declare(strict_types=1);

namespace LiteValidate\Tests;

use PHPUnit\Framework\TestCase;
use LiteValidate\Validator;
use LiteValidate\Attribute\{Required, Email, Length, Unique, Exists};
use PDO;

class TestUserDto
{
    #[Required]
    #[Length(min: 3)]
    public string $name;

    #[Required]
    #[Email]
    #[Unique(table: 'users', column: 'email', exceptField: 'id')]
    public string $email;

    #[Required]
    #[Exists(table: 'roles', column: 'id')]
    public int $roleId;

    public ?int $id = null;
}

class I18nAndDbValidationTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $this->pdo->exec("CREATE TABLE users (id INTEGER PRIMARY KEY AUTOINCREMENT, email TEXT UNIQUE)");
        $this->pdo->exec("CREATE TABLE roles (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT)");

        $this->pdo->exec("INSERT INTO users (email) VALUES ('existing@example.com')");
        $this->pdo->exec("INSERT INTO roles (id, name) VALUES (1, 'Admin'), (2, 'Editor')");

        Validator::setPdo($this->pdo);
        Validator::setLocale('en');
    }

    public function testEnglishMessagesByDefault(): void
    {
        Validator::setLocale('en');

        $result = Validator::validate([
            'name' => 'Al',
            'email' => 'invalid-email',
            'roleId' => 999, // non-existent role
        ], TestUserDto::class);

        $this->assertFalse($result->isValid());
        $errors = $result->getErrors();

        $this->assertStringContainsString('must be at least 3 characters', $errors['name'][0]);
        $this->assertStringContainsString('must be a valid email address', $errors['email'][0]);
        $this->assertStringContainsString('does not exist', $errors['roleId'][0]);
    }

    public function testVietnameseMessages(): void
    {
        Validator::setLocale('vi');

        $result = Validator::validate([
            'name' => '',
            'email' => 'not-an-email',
            'roleId' => 999,
        ], TestUserDto::class);

        $this->assertFalse($result->isValid());
        $errors = $result->getErrors();

        $this->assertSame('Trường name không được để trống.', $errors['name'][0]);
        $this->assertSame('Trường email phải là một địa chỉ email hợp lệ.', $errors['email'][0]);
        $this->assertSame('roleId đã chọn không tồn tại.', $errors['roleId'][0]);
    }

    public function testUniqueRulePreventsDuplicates(): void
    {
        Validator::setLocale('en');

        // Attempting to register existing email
        $result = Validator::validate([
            'name' => 'Alice',
            'email' => 'existing@example.com',
            'roleId' => 1,
        ], TestUserDto::class);

        $this->assertFalse($result->isValid());
        $this->assertStringContainsString('already been taken', $result->getErrors()['email'][0]);

        // Same email but exceptField matches existing id (updating own profile)
        $resultUpdate = Validator::validate([
            'id' => 1,
            'name' => 'Alice Updated',
            'email' => 'existing@example.com',
            'roleId' => 1,
        ], TestUserDto::class);

        $this->assertTrue($resultUpdate->isValid());
    }

    public function testExistsRulePassesWhenFound(): void
    {
        $result = Validator::validate([
            'name' => 'Bob',
            'email' => 'bob@example.com',
            'roleId' => 2, // Role 2 exists
        ], TestUserDto::class);

        $this->assertTrue($result->isValid());
    }
}
