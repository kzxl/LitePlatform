# LiteValidate 🎯

[![Latest Version](https://img.shields.io/github/v/release/kzxl/LiteValidate?label=version&color=blue)](https://github.com/kzxl/LiteValidate/releases)
[![PHP Version](https://img.shields.io/badge/php-%3E%3D8.2-8892BF.svg)](https://php.net)
[![License: MIT](https://img.shields.io/badge/License-MIT-green.svg)](LICENSE)

High-performance, lightweight PHP 8.2+ attribute-based DTO and request validation engine with zero external dependencies and first-class Slim 4 / PSR-15 middleware.

[![PHP 8.2+](https://img.shields.io/badge/PHP-8.2+-blue.svg)](https://php.net)
[![License: MIT](https://img.shields.io/badge/License-MIT-blue.svg)](LICENSE)
[![Zero External Dependencies](https://img.shields.io/badge/Dependencies-0-brightgreen.svg)]()
[![Tests](https://img.shields.io/badge/Tests-16%2F16%20Pass%20(100%25)-brightgreen.svg)]()

---

## ✨ Features

- **PHP 8.2 Attributes**: Declare validation rules directly on class properties (`#[Required]`, `#[Email]`, `#[Length]`, `#[Range]`, `#[In]`, `#[Regex]`, `#[Unique]`, `#[Exists]`).
- **Conditional Validation**: High-level cross-field logic with `#[RequiredIf('field', 'value')]` and `#[ValidateIf('field', 'value', new Rule())]`.
- **JSON Schema (Draft-07) Generation**: Instant 1-to-1 conversion from DTO attributes to standard JSON Schema via `Validator::toJsonSchema(Dto::class)` for OpenAPI 3.0.3, Swagger UI, and dynamic form builders.
- **Zero External Dependencies**: Standalone engine requiring only pure PHP 8.2.
- **Nested DTOs**: Recursively validates and hydrates nested objects (`#[Nested]`).
- **Slim 4 & PSR-15 Middleware**: `ValidationMiddleware` automatically intercepts request payload, hydrates typed DTO into `$request->getAttribute('dto')`, and responds with standard 422 JSON on error.

---

## 📦 Installation

### Option 1: Standard Composer (via Packagist)
```bash
composer require kzxl/lite-validate
```

### Option 2: Direct from Git Repository (VCS)
To pull directly from the official GitHub repository without waiting for Packagist synchronization, add the VCS repository to your project's `composer.json`:

```json
{
    "repositories": [
        {
            "type": "vcs",
            "url": "https://github.com/kzxl/LiteValidate.git"
        }
    ],
    "require": {
        "kzxl/lite-validate": "^1.1.0"
    }
}
```
Or configure via CLI:
```bash
composer config repositories.lite-validate vcs https://github.com/kzxl/LiteValidate.git
composer require kzxl/lite-validate:^1.1.0
```

### Option 3: Local Path Repository (Monorepo / Development)
For local development where changes should reflect immediately via symlink:
```json
{
    "repositories": [
        {
            "type": "path",
            "url": "../libs/LiteValidate",
            "options": {
                "symlink": true
            }
        }
    ],
    "require": {
        "kzxl/lite-validate": "@dev"
    }
}
```

---

## 🚀 Usage Guide

### 1. Define Request DTO with Attributes

```php
use LiteValidate\Attribute\{Required, Email, Length, Range, Positive, In, Nested};

class CreateUserDto
{
    #[Required, Length(min: 3, max: 50)]
    public string $name;

    #[Required, Email]
    public string $email;

    #[Range(min: 18, max: 100)]
    public int $age;

    #[Positive]
    public float $salary;

    #[In(['ADMIN', 'USER', 'GUEST'])]
    public string $role = 'USER';

    #[Nested]
    public AddressDto $address;
}
```

### 2. Validate in Application Code

```php
use LiteValidate\Validator;

$result = Validator::validate($_POST, CreateUserDto::class);

if ($result->isFailed()) {
    // Returns array of errors: ['name' => ['The name field must be at least 3 characters.']]
    $errors = $result->getErrors();
} else {
    /** @var CreateUserDto $dto */
    $dto = $result->validated();
    echo $dto->name;
}

// Or validate and hydrate directly (throws ValidationException on error):
$dto = Validator::make(CreateUserDto::class, $_POST);
```

### 3. One-line Middleware in Slim 4

```php
use Slim\Factory\AppFactory;
use LiteApi\Http\ApiResponse;
use LiteValidate\Middleware\ValidationMiddleware;

$app = AppFactory::create();

$app->post('/api/users', function ($request, $response) {
    /** @var CreateUserDto $dto */
    $dto = $request->getAttribute('dto'); // Fully validated & typed!

    return ApiResponse::created($response, $dto);
})->add(new ValidationMiddleware(CreateUserDto::class, $app->getResponseFactory()));
```

### 4. Conditional Validation (`RequiredIf` & `ValidateIf`)

Validate fields conditionally based on other fields in the same payload:

```php
use LiteValidate\Attribute\{Required, RequiredIf, ValidateIf, Email, Length};

class InvoiceDto
{
    #[Required]
    public string $type; // 'personal' or 'company'

    // Required only when type === 'company'
    #[RequiredIf('type', 'company')]
    public ?string $taxNumber = null;

    // Required when type is either 'company' or 'enterprise'
    #[RequiredIf('type', ['company', 'enterprise'])]
    public ?string $companyName = null;

    // Apply Email validation only when type === 'company'
    #[ValidateIf('type', 'company', new Email())]
    public ?string $companyEmail = null;
}
```

### 5. Export Standard JSON Schema (Draft-07)

Instantly generate Draft-07 JSON Schema from your DTO for OpenAPI 3.0 / Swagger UI documentation, client-side validation, or dynamic form generators:

```php
use LiteValidate\Validator;

$schema = Validator::toJsonSchema(InvoiceDto::class, 'InvoiceRequest');

header('Content-Type: application/json');
echo json_encode($schema, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
```

---

## 🧪 Testing

```bash
composer test
# or
./vendor/bin/phpunit
```

---

## 📄 License

MIT License — see [LICENSE](LICENSE) for details.  
Architected by **Phong Vo** (`kzxl`).


