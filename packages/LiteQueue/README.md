# LiteQueue

[![Latest Version](https://img.shields.io/github/v/release/kzxl/LiteQueue?label=version&color=blue)](https://github.com/kzxl/LiteQueue/releases)
[![PHP Version](https://img.shields.io/badge/php-%3E%3D8.2-8892BF.svg)](https://php.net)
[![License: MIT](https://img.shields.io/badge/License-MIT-green.svg)](LICENSE)

Ultra-lightweight, zero-dependency Background Job Queue & Worker library for PHP 8.2+.

Designed for high-throughput, mission-critical applications that require robust asynchronous task processing without heavyweight dependencies or external services (e.g., Redis, RabbitMQ, Supervisor).

## Key Features

- **ACID Transaction Atomic Locking**: Guarantees zero duplicate job processing on SQLite, MySQL, and PostgreSQL via atomic reservation.
- **Smart Retries with Backoff**: Automatic retries with exponential/configurable backoff before moving permanently to the Dead Letter Queue.
- **Dead Letter Queue (Failed Jobs)**: Complete inspection, tracking, and single-command retry for failed jobs (`getFailedJobs()`, `retryFailedJob()`).
- **Sync Driver for Testing**: Run jobs synchronously in-process during unit tests or local development without running a background worker.
- **Graceful Worker Daemon**: Memory leak monitoring (`--memory=128`), configurable polling interval (`--sleep=3`), and max job limits.

## 📦 Installation

### Option 1: Standard Composer (via Packagist)
```bash
composer require kzxl/lite-queue
```

### Option 2: Direct from Git Repository (VCS)
To pull directly from the official GitHub repository without waiting for Packagist synchronization, add the VCS repository to your project's `composer.json`:

```json
{
    "repositories": [
        {
            "type": "vcs",
            "url": "https://github.com/kzxl/LiteQueue.git"
        }
    ],
    "require": {
        "kzxl/lite-queue": "^1.1.0"
    }
}
```
Or configure via CLI:
```bash
composer config repositories.lite-queue vcs https://github.com/kzxl/LiteQueue.git
composer require kzxl/lite-queue:^1.1.0
```

### Option 3: Local Path Repository (Monorepo / Development)
For local development where changes should reflect immediately via symlink:
```json
{
    "repositories": [
        {
            "type": "path",
            "url": "../libs/LiteQueue",
            "options": {
                "symlink": true
            }
        }
    ],
    "require": {
        "kzxl/lite-queue": "@dev"
    }
}
```

## Quick Start

### 1. Define a Job

```php
use LiteQueue\Job\AbstractJob;

class SendInvoiceEmailJob extends AbstractJob
{
    public function __construct(
        public readonly int $invoiceId,
        public readonly string $recipientEmail,
    ) {}

    public function tries(): int
    {
        return 3; // Retry up to 3 times
    }

    public function backoff(): int
    {
        return 10; // Wait 10s between retries
    }

    public function handle(): void
    {
        // Business logic: send email via SMTP or Mail API
    }

    public function failed(\Throwable $exception): void
    {
        // Hook when all 3 attempts have failed (e.g. alert admin)
    }
}
```

### 2. Dispatch a Job

```php
use LiteQueue\QueueManager;
use LiteQueue\Driver\DatabaseQueueDriver;

$pdo = new PDO('sqlite:/path/to/database.sqlite');
$driver = new DatabaseQueueDriver($pdo);
$queue = new QueueManager($driver);

// Dispatch immediately to default queue
$queue->push(new SendInvoiceEmailJob(101, 'customer@example.com'));

// Dispatch with delay (e.g. 5 minutes)
$queue->later(300, new SendInvoiceEmailJob(102, 'vip@example.com'));
```

### 3. Run the Worker

```bash
php bin/worker.php --queue=default --sleep=3 --memory=128 --tries=3
```

Or programmatically in PHP:

```php
use LiteQueue\Worker;
use LiteQueue\WorkerOptions;

$worker = new Worker($queue);

// Run single job
$worker->runNext('default');

// Run infinite daemon
$worker->daemon('default', new WorkerOptions(maxAttempts: 3, sleep: 3));
```

---

### 4. Real-Time WebSocket Bridge (with LiteSocket)

Broadcast job completion or failure events to client WebSocket rooms/channels in real time:

```php
use LiteQueue\Job\AbstractJob;
use LiteQueue\Job\BroadcastableJobInterface;
use LiteQueue\Job\BroadcastableJobTrait;
use LiteQueue\Bridge\LiteSocketBridge;

// 1. Define a broadcastable job
class ExportInventoryJob extends AbstractJob implements BroadcastableJobInterface
{
    use BroadcastableJobTrait;

    public function __construct(
        public readonly int $userId,
        public readonly array $filters
    ) {
        // Broadcast to user's private WebSocket channel
        $this->setBroadcast("user_{$this->userId}", 'export.completed');
    }

    public function handle(): void
    {
        // Heavy report generation logic...
        $fileUrl = 'https://example.com/downloads/report.xlsx';

        // Set client payload
        $this->broadcastData = ['download_url' => $fileUrl];
    }
}

// 2. Attach Bridge to Worker daemon
$bridge = LiteSocketBridge::tcp('127.0.0.1', 8088);
// Or in-process: $bridge = LiteSocketBridge::inProcess($webSocketServer);

$bridge->attach($worker);
$worker->daemon('default');
```

## 🧪 Testing

```bash
composer install
vendor/bin/phpunit
```

## 📄 License

MIT License — see [LICENSE](LICENSE) for details.

