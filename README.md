# LitePlatform Monorepo

**Sovereign, High-Performance, Zero-Bloat Full-Stack PHP 8.2+ Monorepo & Shared Hosting Framework.**

Designed as a lightweight alternative to monolithic PHP frameworks (Laravel) and legacy CMSs (WordPress). Unifies all **12 sovereign `Lite*` packages** under `packages/` with PSR-4 native autoloading, built-in SEO & Security hardening, Shared Hosting deployment tooling, and **< 2 MB production footprint**.

---

## Monorepo Architecture

```text
LitePlatform/
├── framework/                # Reusable platform runtime (PSR-4: Lite\Framework\*)
│   ├── Core/                 # Kernel, AppBootstrap, MigrationManager, Routing, Tenancy
│   ├── Security/             # AdminToken (TTL), CsrfGuard, LoginThrottle, UploadGuard
│   ├── Seo/                  # SeoMeta, SchemaBuilder (JSON-LD), SitemapGenerator
│   ├── Media/                # ImageOptimizer (GD -> WebP + CLS dimensions)
│   ├── Middleware/           # AdminAuth, PageCache, Redirect301, SecurityHeaders
│   ├── Providers/            # Opt-in service providers
│   ├── Console/              # ReleaseBuilder (smart active-package Shared Hosting bundler)
│   ├── Dev/                  # StudioController, DevController (excluded from production release)
│   └── Testing/              # Unified PHPUnit Web TestCase
├── packages/                 # 12 Sovereign PHP 8.2+ libraries (PSR-4 autoloaded)
│   ├── LiteORM/              # Micro-ORM, Unit of Work, Migrations, SQLi-guarded QueryBuilder
│   ├── LiteCache/            # FileOpCache, MySQL/SQLite PDO cache, Atomic Locks, safe unserialize
│   ├── LiteView/             # OpCache Blade-like templates, components, HTMX fragments
│   ├── LiteValidate/         # Attribute-driven typed DTO validation & JSON Schema
│   ├── LiteUtil/             # Vietnamese currency/date/diacritics/slug utilities
│   ├── LiteAdmin/            # Declarative Auto-CRUD dashboard with RBAC & export
│   ├── LiteApi/              # PSR-7/15 JSON envelope, rate limiting, HS256 JWT, OpenAPI
│   ├── LiteAudit/            # Entity mutation ledger & actor history
│   ├── LiteExport/           # Streaming XLSX/CSV with CWE-1236 formula defense
│   ├── LiteQueue/            # ACID database job queue (Cron batch or daemon)
│   ├── LiteInertia/          # Inertia v2 protocol adapter (opt-in)
│   └── LiteSocket/           # RFC 6455 WebSocket & SSE server (VPS opt-in)
├── app/                      # 100% Application business code (PSR-4: App\*)
│   ├── Controllers/          # AuthController (DB password_verify + throttle), Home, Api, Product
│   ├── DTOs/                 # Request DTOs
│   ├── Entities/             # Domain entities
│   └── Modules/              # Domain feature modules
├── config/                   # Opt-in providers, DB, cache, SEO, security & routes
├── database/                 # Cross-engine migrations & seeders
├── public/                   # index.php + Shared Hosting .htaccess
├── templates/                # LiteView SSR / HTMX layouts & components
├── tests/                    # Platform integration & security tests
└── phpunit.xml               # Single unified PHPUnit runner (Platform + all 12 packages)
```

> **Notice:** All 12 standalone `kzxl/Lite*` GitHub repositories have been consolidated into `packages/` and archived. `kzxl/LitePlatform` is the single source of truth.

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

### 4. Architecture Preset Switcher & Shared Hosting Deployment
Tune the skeleton for specific workloads in 1 command:
- **`preset:web-seo`**: Shared Hosting + SEO preset (ORM, LiteCache + PageCache, LiteView, SEO, Admin; disables Multi-Tenancy).
- **`preset:api`**: REST API / Microservice (< 1.5MB RAM, excludes View, Admin, Inertia).
- **`preset:htmx`**: Server-Side Rendered interactive HTML with HTMX partial fragments.
- **`preset:admin`**: Internal Auto-CRUD Backoffice with audit logging.
- **`preset:full`**: Enables all sovereign providers.
- **`cache:clear`**: Purges compiled views, route cache, and LiteCache files.
- **`build:release`**: Stages a lean Shared Hosting bundle in `dist/release/` (excludes tests & dev vendor packages).
- **`composer test:all`**: Runs both platform integration tests (`php bin/lite test`) and all 12 package PHPUnit suites.

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
