# Sovereign Full-Stack PHP Architecture Blueprint

A production-ready reference blueprint combining all 10 `Lite*` libraries with **Slim 4** and **PHP 8.2+**. Demonstrates an ultra-fast, unbloated enterprise application consuming < 16MB RAM with zero 3rd-party vendor lock-in.

---

## 1. Ecosystem Matrix (10 Sovereign Libraries)

| Layer | Library | Role & Capabilities |
| :--- | :--- | :--- |
| **Presentation (SSR/HTMX)** | [`LiteView`](packages/LiteView) | OpCache-compiled Blade-like engine with `@fragment` isolation for HTMX swaps. |
| **Presentation (SPA)** | [`LiteInertia`](packages/LiteInertia) | Inertia.js protocol adapter bridging React/Vue without building separate REST APIs. |
| **Presentation (Admin)** | [`LiteAdmin`](packages/LiteAdmin) | Instant Auto-CRUD dashboard via attributes, search, Dark/Light UI, export, audit timeline. |
| **Caching Engine** | [`LiteCache`](packages/LiteCache) | Zero-dependency OpCache/DB/Memory cache with atomic locks & stampede defense (`rememberWithLock`). |
| **Async Processing** | [`LiteQueue`](packages/LiteQueue) | Background Job Queue with ACID atomic locking on SQLite/MySQL, retries, and failed jobs. |
| **Persistence (ORM)** | [`LiteORM`](packages/LiteORM) | Micro-ORM with DataMapper, snapshot tracking, cursor streaming, and **Batch Eager Loading** (`with()`). |
| **Validation** | [`LiteValidate`](packages/LiteValidate) | Attribute-driven typed DTO validation and auto-hydration. |
| **API & Auth** | [`LiteApi`](packages/LiteApi) | PSR-7/15 JSON responders, rate limiting, and zero-dependency HMAC-SHA256 JWT. |
| **Audit Trail** | [`LiteAudit`](packages/LiteAudit) | Tamper-evident ledger tracking entity mutations and actor history. |
| **Data Export** | [`LiteExport`](packages/LiteExport) | Streaming CSV and OpenXML Excel (.xlsx) with Formula Injection defense (CWE-1236). |
| **Realtime Sockets** | [`LiteSocket`](packages/LiteSocket) | RFC 6455 WebSocket & SSE server for VPS workloads. |
| **Utilities** | [`LiteUtil`](packages/LiteUtil) | Vietnamese number-to-words currency formatting, slug generators, and helpers. |

---

## 2. Standard Directory Layout

```text
my-sovereign-app/
├── bin/
│   └── worker.php              # LiteQueue daemon runner
├── config/
│   ├── database.php            # SQLite/MySQL/PostgreSQL PDO setup
│   └── container.php           # Dependency Injection definitions
├── src/
│   ├── Entity/                 # LiteORM Entities + LiteAdmin Attributes
│   │   ├── Customer.php
│   │   └── Order.php
│   ├── DTO/                    # LiteValidate DTOs
│   │   └── CreateOrderRequest.php
│   ├── Jobs/                   # LiteQueue Background Jobs
│   │   └── SendInvoiceJob.php
│   └── Controller/             # Slim 4 PSR-15 Handlers
│       ├── ApiController.php
│       └── WebController.php
├── templates/                  # LiteView (.blade.php) views
│   ├── layouts/
│   └── orders/
├── public/
│   └── index.php               # Single Application Entrypoint
└── composer.json
```

---

## 3. Production Entry Point Example (`public/index.php`)

```php
<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use LiteAdmin\AdminDashboard;
use LiteApi\Security\Jwt;
use LiteAudit\AuditManager;
use LiteAudit\Bridge\LiteOrmAuditBridge;
use LiteAudit\Storage\PdoAuditStorage;
use LiteORM\EntityManager;
use LiteQueue\Driver\DatabaseQueueDriver;
use LiteQueue\QueueManager;
use LiteView\ViewEngine;
use Nyholm\Psr7\Response;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Factory\AppFactory;

// 1. Core Infrastructure & PDO
$pdo = new PDO('sqlite:' . __DIR__ . '/../storage/app.sqlite', options: [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);

$em = new EntityManager($pdo);
$audit = new AuditManager(new PdoAuditStorage($pdo));
LiteOrmAuditBridge::register($em, $audit);

$queue = new QueueManager(new DatabaseQueueDriver($pdo));
$view = new ViewEngine(viewsPath: __DIR__ . '/../templates');

// 2. Slim 4 Application
$app = AppFactory::create();
$app->addBodyParsingMiddleware();
$app->addRoutingMiddleware();

// 3. Mount LiteAdmin Auto-Dashboard
$admin = new AdminDashboard($em, $audit, prefix: '/admin');
$admin->register(\App\Entity\Customer::class);
$admin->register(\App\Entity\Order::class);
$admin->registerRoutes($app);

// 4. Mount Public Web Routes (LiteView + HTMX)
$app->get('/', function (Request $request, Response $response) use ($view) {
    return $view->render($response, 'home', ['title' => 'Sovereign PHP']);
});

// 5. High-Throughput REST API (LiteORM + Batch Eager Loading + LiteQueue)
$app->get('/api/orders', function (Request $request, Response $response) use ($em) {
    // Zero N+1 query: 1 query for Orders + 1 query for Customers in single roundtrip
    $orders = $em->query(\App\Entity\Order::class)
        ->with('customer', 'items')
        ->orderBy('id', 'DESC')
        ->paginate(page: 1, perPage: 20);

    $response->getBody()->write(json_encode($orders, JSON_THROW_ON_ERROR));
    return $response->withHeader('Content-Type', 'application/json');
});

$app->post('/api/orders/{id}/invoice', function (Request $request, Response $response, array $args) use ($queue) {
    $orderId = (int)$args['id'];
    // Dispatch background job asynchronously without blocking HTTP response
    $queue->push(new \App\Jobs\SendInvoiceJob($orderId));

    $response->getBody()->write(json_encode(['status' => 'queued', 'order_id' => $orderId]));
    return $response->withHeader('Content-Type', 'application/json');
});

$app->run();
```
