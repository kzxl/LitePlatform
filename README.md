# LitePlatform

**Sovereign, High-Performance, Zero-External-Dependency Full-Stack PHP 8.2+ Ecosystem.**

Designed as a modern, unbloated alternative to monolithic PHP frameworks (e.g. Laravel) and legacy CMSs (e.g. WordPress, Joomla). Provides an entire full-stack suite consuming **< 2MB RAM** with **100% PSR compliance** (PSR-7, PSR-11, PSR-15) and achieving **23,000+ RPS** at sub-millisecond latencies.

---

## 🏛️ Ecosystem Overview (10 Sovereign Libraries)

All libraries are pulled directly from their official GitHub repositories via Composer Git VCS with `"no-api": true`, ensuring `git clone` and `composer install` work anywhere without relying on local filesystem paths.

| Package | GitHub Repository | Packagist | Description |
| :--- | :--- | :--- | :--- |
| **`LiteORM`** | [kzxl/LiteORM](https://github.com/kzxl/LiteORM) | `kzxl/lite-orm` | High-performance Micro-ORM, Unit of Work, Dirty Checking, LINQ-style syntax & **Batch Eager Loading** (`with()`). |
| **`LiteQueue`** | [kzxl/LiteQueue](https://github.com/kzxl/LiteQueue) | `kzxl/lite-queue` | Zero-dependency Background Job Queue & Worker with **ACID Atomic Locking** on SQLite/MySQL, retries & DLQ. |
| **`LiteAdmin`** | [kzxl/LiteAdmin](https://github.com/kzxl/LiteAdmin) | `kzxl/lite-admin` | Instant Auto-CRUD Dashboard via Entity Attributes with Dark/Light UI, Excel export, and Audit trail. |
| **`LiteView`** | [kzxl/LiteView](https://github.com/kzxl/LiteView) | `kzxl/lite-view` | OpCache-compiled Blade-like template engine with native **HTMX Partial Fragment Isolation** (`@fragment`). |
| **`LiteInertia`** | [kzxl/LiteInertia](https://github.com/kzxl/LiteInertia) | `kzxl/lite-inertia` | Zero-dependency Inertia.js protocol adapter bridging React & Vue without writing REST APIs. |
| **`LiteApi`** | [kzxl/LiteApi](https://github.com/kzxl/LiteApi) | `kzxl/lite-api` | High-throughput REST API toolkit with zero-dependency **HS256 JWT**, rate limiting & Problem Details. |
| **`LiteValidate`** | [kzxl/LiteValidate](https://github.com/kzxl/LiteValidate) | `kzxl/lite-validate` | PHP 8.2+ Attribute-driven typed DTO validation and auto-hydration engine. |
| **`LiteExport`** | [kzxl/LiteExport](https://github.com/kzxl/LiteExport) | `kzxl/lite-export` | Streaming OpenXML Excel (.xlsx) and CSV exporter (<16MB RAM) with **CWE-1236 Formula Injection mitigation**. |
| **`LiteAudit`** | [kzxl/LiteAudit](https://github.com/kzxl/LiteAudit) | `kzxl/lite-audit` | Tamper-evident entity audit trail ledger and mutation timeline visualizer for LiteORM. |
| **`LiteUtil`** | [kzxl/LiteUtil](https://github.com/kzxl/LiteUtil) | `kzxl/lite-util` | Multilingual number-to-words currency formatters (Vietnamese, English), slug generators & helpers. |

---

## ⚡ Performance Matrix (Measured Benchmarks)

Dispatched through full PSR-15 Middleware Pipeline, Routing, Dynamic Reflection, and Response Serializer:

| Metric | Fullstack Skeleton | API Microservice (`preset:api`) | Route-Cached (`route:cache`) | Laravel 11 (FPM) |
| :--- | :--- | :--- | :--- | :--- |
| **Base Memory** | **1.46 MB** | **0.82 MB** | **1.20 MB** | ~18–35 MB (Save 95%) |
| **Peak Memory** | **1.99 MB** | **1.35 MB** | **1.80 MB** | ~25–50 MB |
| **Kernel Boot Time** | 61.9 ms | 37.8 ms | **18.2 ms** | 120–250 ms |
| **Throughput (RPS)** | **12,405 RPS** | **13,707 RPS** | **23,325 RPS** | ~1,200–2,000 RPS |
| **Average Latency** | 0.081 ms (81 µs) | 0.073 ms (73 µs) | **0.043 ms (43 µs)** | ~0.8–2.5 ms |
| **ORM Read & Hydration** | **6,266 RPS** (0.16 ms) | — | — | ~600–1,100 RPS |
| **Test Suite Execution** | **56.6 ms** (In-memory) | — | — | ~1,500–4,000 ms |

---

## 🚀 Quick Start in 60 Seconds

```bash
# 1. Clone the repository
git clone https://github.com/kzxl/LitePlatform.git my-app
cd my-app

# 2. Install sovereign dependencies from Git VCS
composer install

# 3. Start local development server
php bin/lite serve --port=8000
```

Open in your browser:
- **HTMX & SSR Dashboard**: `http://localhost:8000/`
- **Auto-CRUD Admin Backoffice**: `http://localhost:8000/admin` *(Credentials: `admin` / `admin123`)*
- **Interactive Swagger-like API Docs**: `http://localhost:8000/api/docs`
- **Embedded Database Studio**: `http://localhost:8000/_studio` *(Active in debug mode)*
- **System Health JSON**: `http://localhost:8000/api/status`

---

## 🛠️ Developer CLI Scaffolding (`bin/lite`)

`LitePlatform` includes an ultra-fast zero-dependency CLI toolkit:

```bash
php bin/lite <command> [options]
```

### 1. Rapid Fullstack Scaffolding
- **`make:crud <Name> [fields...]`**: Scaffolds a complete feature in 1 command (Migration, Entity with LiteAdmin attributes, DTO with LiteValidate, Controller, and auto-appends Routes & Admin registration).
  ```bash
  php bin/lite make:crud Product --fields="name:string,price:float,stock:int,is_active:bool"
  ```
- **`make:test <Name>`**: Scaffolds an in-memory integration test for the entity:
  ```bash
  php bin/lite make:test Product
  ```
- **`test`**: Executes the built-in zero-dependency in-memory test suite in < 60ms:
  ```bash
  php bin/lite test
  ```

### 2. Database & Reverse-Engineering
- **`db:inspect`**: Tabular visual inspection of database tables, column types, nullability, primary keys, and row counts.
- **`db:generate [table]`**: Reverse-engineers existing database tables directly into typed LiteORM Entity classes with zero manual code writing.
- **`migrate`**: Runs pending migrations with ACID tracking in the `migrations` table.
- **`make:migration <name>`**: Scaffolds a cross-engine timestamped migration file.
- **`db:seed`**: Seeds the database using `DatabaseSeeder`.

### 3. Developer Experience & High Performance
- **`tinker`**: Interactive terminal REPL preloaded with `$app`, `$container`, `$em` (EntityManager), `$db` (PDO), `$audit` (AuditManager).
  ```bash
  lite > $em->find(App\Entities\Product::class, 1)->name
  => "Logitech G Pro X"
  ```
- **`route:cache`**: Compiles route definitions into static array cache, unlocking **23,000+ RPS** and 43µs response times.
- **`route:clear`**: Clears compiled route cache.
- **`watch`**: Zero-Node file watcher. Uses Server-Sent Events (`/dev/live-reload`) to automatically reload the browser on code edits without installing Node.js, Vite, or npm packages!

### 4. Architecture Preset Switcher
Tune the skeleton for specific workloads in 1 command:
- **`preset:api`**: REST API / Microservice (< 1.5MB RAM, excludes View, Admin, Inertia).
- **`preset:htmx`**: Server-Side Rendered interactive HTML with HTMX partial fragments.
- **`preset:admin`**: Internal Auto-CRUD Backoffice with audit logging.
- **`preset:full`**: Enables all 10 sovereign libraries.

### 5. Atomic Generators
- `make:controller <Name>`: Scaffold a PSR-15 Controller.
- `make:entity <Name>`: Scaffold a LiteORM Entity with LiteAdmin attributes.
- `make:dto <Name>`: Scaffold a typed DTO with LiteValidate attributes.
- `make:job <Name>`: Scaffold a LiteQueue asynchronous background Job.

---

## 🎨 Zero-Dependency Reusable HTMX Components

Pre-built UI components located in `templates/components/`:
- **`datatable.lite.php`**: Real-time client/server search, column sorting, and pagination powered by HTMX attributes.
- **`modal.lite.php`**: Accessible, CSS-animated HTMX modal dialog.
- **`toast.lite.php`**: Auto-dismissing notification toasts.

---

## 🐳 Production Deployment with FrankenPHP

Deploy with native **FrankenPHP Worker Mode** (Caddy-based) for automated HTTPS and 25,000+ RPS:

```bash
docker compose up -d --build
```

---

## 📄 License

MIT License — see [LICENSE](LICENSE) for details.
