<?php

declare(strict_types=1);

namespace App\Generators;

/**
 * Rapid CRUD generator scaffolding Migration, Entity, DTO, Controller, and Routes in seconds.
 */
class CrudGenerator
{
    public function __construct(private string $baseDir)
    {
    }

    /**
     * Generate complete CRUD stack.
     *
     * @param string $name Model name (e.g. 'Product')
     * @param string $fieldsDef Field definitions (e.g. 'name:string,price:float,stock:int,is_active:bool')
     * @return array<string, string> List of generated files
     */
    public function generate(string $name, string $fieldsDef = ''): array
    {
        $name = ucfirst($name);
        $plural = strtolower($name) . 's';
        $fields = $this->parseFields($fieldsDef);

        $results = [];

        // 1. Generate Migration
        $migrationFile = $this->generateMigration($name, $plural, $fields);
        $results['migration'] = $migrationFile;

        // 2. Generate Entity
        $entityFile = $this->generateEntity($name, $plural, $fields);
        $results['entity'] = $entityFile;

        // 3. Generate DTO
        $dtoFile = $this->generateDto($name, $fields);
        $results['dto'] = $dtoFile;

        // 4. Generate Controller
        $controllerFile = $this->generateController($name, $plural, $fields);
        $results['controller'] = $controllerFile;

        // 5. Append routes to config/routes.php
        $this->appendRoutes($name, $plural);

        // 6. Register entity in config/app.php for LiteAdmin
        $this->registerAdminEntity($name);

        return $results;
    }

    /**
     * Parse field definitions: 'name:string,price:float,stock:int'
     *
     * @return array<string, string>
     */
    private function parseFields(string $fieldsDef): array
    {
        $fields = [];
        if (trim($fieldsDef) === '') {
            return ['name' => 'string', 'description' => 'string'];
        }

        $parts = explode(',', $fieldsDef);
        foreach ($parts as $part) {
            $part = trim($part);
            if ($part === '') continue;

            if (str_contains($part, ':')) {
                [$field, $type] = explode(':', $part, 2);
                $fields[trim($field)] = trim($type);
            } else {
                $fields[$part] = 'string';
            }
        }

        return $fields;
    }

    private function generateMigration(string $name, string $plural, array $fields): string
    {
        $dir = $this->baseDir . '/database/migrations';
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }

        $timestamp = date('Y_m_d_His');
        $fileName = "{$timestamp}_create_{$plural}_table.php";
        $filePath = "{$dir}/{$fileName}";

        $sqliteCols = ["id INTEGER PRIMARY KEY AUTOINCREMENT"];
        $mysqlCols = ["id BIGINT AUTO_INCREMENT PRIMARY KEY"];
        $pgsqlCols = ["id SERIAL PRIMARY KEY"];

        foreach ($fields as $field => $type) {
            $sqlType = match ($type) {
                'int', 'integer' => 'INTEGER',
                'float', 'double', 'decimal' => 'REAL',
                'bool', 'boolean' => 'INTEGER',
                'text' => 'TEXT',
                default => 'VARCHAR(255)',
            };
            $mysqlType = match ($type) {
                'int', 'integer' => 'INT',
                'float', 'double' => 'DECIMAL(10, 2)',
                'bool', 'boolean' => 'TINYINT(1) DEFAULT 0',
                'text' => 'TEXT',
                default => 'VARCHAR(255)',
            };

            $sqliteCols[] = "{$field} {$sqlType} NOT NULL";
            $mysqlCols[] = "{$field} {$mysqlType} NOT NULL";
            $pgsqlCols[] = "{$field} {$sqlType} NOT NULL";
        }

        $sqliteCols[] = "created_at DATETIME DEFAULT CURRENT_TIMESTAMP";
        $sqliteCols[] = "updated_at DATETIME NULL";

        $mysqlCols[] = "created_at DATETIME DEFAULT CURRENT_TIMESTAMP";
        $mysqlCols[] = "updated_at DATETIME NULL";

        $pgsqlCols[] = "created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP";
        $pgsqlCols[] = "updated_at TIMESTAMP NULL";

        $sqliteSql = "CREATE TABLE IF NOT EXISTS {$plural} (\n                    " . implode(",\n                    ", $sqliteCols) . "\n                );";
        $mysqlSql = "CREATE TABLE IF NOT EXISTS {$plural} (\n                    " . implode(",\n                    ", $mysqlCols) . "\n                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;";
        $pgsqlSql = "CREATE TABLE IF NOT EXISTS {$plural} (\n                    " . implode(",\n                    ", $pgsqlCols) . "\n                );";

        $content = <<<PHP
<?php

declare(strict_types=1);

return new class {
    public function up(PDO \$pdo): void
    {
        \$driver = \$pdo->getAttribute(PDO::ATTR_DRIVER_NAME);

        \$sql = match (\$driver) {
            'sqlite' => "
                {$sqliteSql}
            ",
            'pgsql' => "
                {$pgsqlSql}
            ",
            default => "
                {$mysqlSql}
            ",
        };

        \$pdo->exec(\$sql);
    }
};
PHP;

        file_put_contents($filePath, $content);
        return "database/migrations/{$fileName}";
    }

    private function generateEntity(string $name, string $plural, array $fields): string
    {
        $dir = $this->baseDir . '/src/Entities';
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }

        $filePath = "{$dir}/{$name}.php";

        $props = [];
        foreach ($fields as $field => $type) {
            $phpType = match ($type) {
                'int', 'integer' => 'int',
                'float', 'double', 'decimal' => 'float',
                'bool', 'boolean' => 'bool',
                default => 'string',
            };
            $colType = match ($type) {
                'int', 'integer' => 'integer',
                'float', 'double', 'decimal' => 'float',
                'bool', 'boolean' => 'boolean',
                default => 'string',
            };
            $adminFieldType = match ($type) {
                'int', 'integer', 'float', 'double', 'decimal' => 'number',
                'bool', 'boolean' => 'checkbox',
                'text' => 'textarea',
                default => 'text',
            };

            $label = ucwords(str_replace('_', ' ', $field));
            $default = match ($phpType) {
                'int' => ' = 0',
                'float' => ' = 0.0',
                'bool' => ' = false',
                default => " = ''",
            };

            $props[] = <<<PHP
    #[Column(type: '{$colType}')]
    #[AdminColumn(label: '{$label}', sortable: true, searchable: true)]
    #[AdminField(label: '{$label}', type: '{$adminFieldType}', required: true)]
    public {$phpType} \${$field}{$default};
PHP;
        }

        $propertiesBlock = implode("\n\n", $props);

        $content = <<<PHP
<?php

declare(strict_types=1);

namespace App\Entities;

use LiteAdmin\Attribute\AdminColumn;
use LiteAdmin\Attribute\AdminField;
use LiteAdmin\Attribute\AdminResource;
use LiteORM\Attribute\AutoIncrement;
use LiteORM\Attribute\Column;
use LiteORM\Attribute\CreatedAt;
use LiteORM\Attribute\Entity;
use LiteORM\Attribute\Id;
use LiteORM\Attribute\Table;
use LiteORM\Attribute\UpdatedAt;

#[Entity]
#[Table(name: '{$plural}')]
#[AdminResource(title: '{$name}s', icon: 'box', group: 'Management')]
class {$name}
{
    #[Id]
    #[AutoIncrement]
    #[Column(type: 'integer')]
    #[AdminColumn(label: 'ID', sortable: true)]
    public ?int \$id = null;

{$propertiesBlock}

    #[Column(name: 'created_at', type: 'datetime')]
    #[CreatedAt]
    #[AdminColumn(label: 'Created At')]
    public ?\DateTimeImmutable \$createdAt = null;

    #[Column(name: 'updated_at', type: 'datetime', nullable: true)]
    #[UpdatedAt]
    public ?\DateTimeImmutable \$updatedAt = null;
}
PHP;

        file_put_contents($filePath, $content);
        return "src/Entities/{$name}.php";
    }

    private function generateDto(string $name, array $fields): string
    {
        $dir = $this->baseDir . '/src/DTOs';
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }

        $className = "Create{$name}Request";
        $filePath = "{$dir}/{$className}.php";

        $props = [];
        foreach ($fields as $field => $type) {
            $phpType = match ($type) {
                'int', 'integer' => 'int',
                'float', 'double', 'decimal' => 'float',
                'bool', 'boolean' => 'bool',
                default => 'string',
            };
            $props[] = <<<PHP
    #[Required]
    public {$phpType} \${$field};
PHP;
        }

        $propertiesBlock = implode("\n\n", $props);

        $content = <<<PHP
<?php

declare(strict_types=1);

namespace App\DTOs;

use LiteValidate\Attribute\Required;

class {$className}
{
{$propertiesBlock}
}
PHP;

        file_put_contents($filePath, $content);
        return "src/DTOs/{$className}.php";
    }

    private function generateController(string $name, string $plural, array $fields): string
    {
        $dir = $this->baseDir . '/src/Controllers';
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }

        $className = "{$name}Controller";
        $filePath = "{$dir}/{$className}.php";

        $content = <<<PHP
<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Entities\\{$name};
use DI\Container;
use LiteApi\Http\ApiResponse;
use LiteORM\EntityManager;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Controller providing REST API CRUD operations for {$name}.
 */
class {$className}
{
    private ?EntityManager \$em;

    public function __construct(private Container \$container)
    {
        \$this->em = \$container->has(EntityManager::class) ? \$container->get(EntityManager::class) : null;
    }

    /**
     * GET /api/{$plural} - List records with pagination
     */
    public function index(Request \$request, Response \$response): Response
    {
        if (!\$this->em) {
            return ApiResponse::error(\$response, 'ORM not configured', 500);
        }

        \$params = \$request->getQueryParams();
        \$page = max(1, (int)(\$params['page'] ?? 1));
        \$limit = min(100, max(1, (int)(\$params['limit'] ?? 20)));

        \$items = \$this->em->query({$name}::class)
            ->orderBy('id', 'DESC')
            ->limit(\$limit)
            ->offset((\$page - 1) * \$limit)
            ->get();

        return ApiResponse::ok(\$response, \$items, ['page' => \$page, 'limit' => \$limit]);
    }

    /**
     * GET /api/{$plural}/{id} - Get single record
     */
    public function show(Request \$request, Response \$response, array \$args): Response
    {
        if (!\$this->em) {
            return ApiResponse::error(\$response, 'ORM not configured', 500);
        }

        \$id = (int)\$args['id'];
        \$item = \$this->em->find({$name}::class, \$id);

        if (!\$item) {
            return ApiResponse::error(\$response, '{$name} not found', 404);
        }

        return ApiResponse::ok(\$response, \$item);
    }

    /**
     * POST /api/{$plural} - Create new record
     */
    public function store(Request \$request, Response \$response): Response
    {
        if (!\$this->em) {
            return ApiResponse::error(\$response, 'ORM not configured', 500);
        }

        \$body = (array)\$request->getParsedBody();
        \$item = new {$name}();

        foreach (\$body as \$key => \$val) {
            if (property_exists(\$item, \$key) && \$key !== 'id') {
                \$ref = new \ReflectionProperty(\$item, \$key);
                \$type = \$ref->getType();
                if (\$type instanceof \ReflectionNamedType) {
                    \$val = match (\$type->getName()) {
                        'bool' => (bool)\$val,
                        'int' => (int)\$val,
                        'float' => (float)\$val,
                        'string' => (string)\$val,
                        default => \$val,
                    };
                }
                \$item->{\$key} = \$val;
            }
        }

        \$this->em->persist(\$item);
        \$this->em->flush();

        return ApiResponse::created(\$response, \$item);
    }

    /**
     * PUT/PATCH /api/{$plural}/{id} - Update record
     */
    public function update(Request \$request, Response \$response, array \$args): Response
    {
        if (!\$this->em) {
            return ApiResponse::error(\$response, 'ORM not configured', 500);
        }

        \$id = (int)\$args['id'];
        \$item = \$this->em->find({$name}::class, \$id);

        if (!\$item) {
            return ApiResponse::error(\$response, '{$name} not found', 404);
        }

        \$body = (array)\$request->getParsedBody();
        foreach (\$body as \$key => \$val) {
            if (property_exists(\$item, \$key) && \$key !== 'id') {
                \$ref = new \ReflectionProperty(\$item, \$key);
                \$type = \$ref->getType();
                if (\$type instanceof \ReflectionNamedType) {
                    \$val = match (\$type->getName()) {
                        'bool' => (bool)\$val,
                        'int' => (int)\$val,
                        'float' => (float)\$val,
                        'string' => (string)\$val,
                        default => \$val,
                    };
                }
                \$item->{\$key} = \$val;
            }
        }

        \$this->em->flush();
        return ApiResponse::ok(\$response, \$item);
    }

    /**
     * DELETE /api/{$plural}/{id} - Delete record
     */
    public function destroy(Request \$request, Response \$response, array \$args): Response
    {
        if (!\$this->em) {
            return ApiResponse::error(\$response, 'ORM not configured', 500);
        }

        \$id = (int)\$args['id'];
        \$item = \$this->em->find({$name}::class, \$id);

        if (!\$item) {
            return ApiResponse::error(\$response, '{$name} not found', 404);
        }

        \$this->em->remove(\$item);
        \$this->em->flush();

        return ApiResponse::ok(\$response, ['message' => 'Deleted successfully']);
    }
}
PHP;

        file_put_contents($filePath, $content);
        return "src/Controllers/{$className}.php";
    }

    private function appendRoutes(string $name, string $plural): void
    {
        $routesFile = $this->baseDir . '/config/routes.php';
        if (!file_exists($routesFile)) {
            return;
        }

        $content = file_get_contents($routesFile);
        if ($content === false || str_contains($content, "/api/{$plural}")) {
            return;
        }

        $controller = "\\App\\Controllers\\{$name}Controller";
        $routesBlock = <<<PHP

    // {$name} REST Endpoints
    \$app->get('/api/{$plural}', [{$controller}::class, 'index']);
    \$app->get('/api/{$plural}/{id}', [{$controller}::class, 'show']);
    \$app->post('/api/{$plural}', [{$controller}::class, 'store']);
    \$app->put('/api/{$plural}/{id}', [{$controller}::class, 'update']);
    \$app->delete('/api/{$plural}/{id}', [{$controller}::class, 'destroy']);
};
PHP;

        $updated = preg_replace('/(\n\s*\};\s*)$/', $routesBlock, $content);
        if ($updated !== null) {
            file_put_contents($routesFile, $updated);
        }
    }

    private function registerAdminEntity(string $name): void
    {
        $appConfig = $this->baseDir . '/config/app.php';
        if (!file_exists($appConfig)) {
            return;
        }

        $content = file_get_contents($appConfig);
        $entityClass = "\\App\\Entities\\{$name}::class";
        if ($content === false || str_contains($content, $entityClass)) {
            return;
        }

        $updated = preg_replace(
            "/'entities'\s*=>\s*\[(.*?)\]/s",
            "'entities' => [$1            {$entityClass},\n        ]",
            $content
        );

        if ($updated !== null) {
            file_put_contents($appConfig, $updated);
        }
    }
}
