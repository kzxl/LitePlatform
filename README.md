# LitePlatform

**Sovereign, High-Performance, Zero-External-Dependency Full-Stack PHP 8.2+ Ecosystem.**

Designed as a modern, unbloated alternative to monolithic PHP frameworks (e.g. Laravel) and legacy CMSs (e.g. WordPress, Joomla). Provides an entire full-stack suite consuming **< 16MB RAM** with **100% PSR compliance** (PSR-7, PSR-11, PSR-15).

---

## 🏛️ Ecosystem Overview (10 Sovereign Libraries)

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

## ⚡ Architectural Highlights

```mermaid
graph TD
    subgraph UI ["Presentation Layer"]
        LV["LiteView (HTMX / SSR)"]
        LI["LiteInertia (React / Vue SPA)"]
        LA["LiteAdmin (Auto-CRUD Dashboard)"]
    end

    subgraph Business ["Application & Async Layer"]
        LQ["LiteQueue (Background Worker)"]
        LVal["LiteValidate (DTO Validation)"]
        LApi["LiteApi (REST / JWT)"]
        LExp["LiteExport (Streaming Excel/CSV)"]
        LAud["LiteAudit (Audit Trail)"]
        LU["LiteUtil (Helpers & Formatters)"]
    end

    subgraph Data ["Persistence Layer"]
        LORM["LiteORM (DataMapper / QueryBuilder / Anti-N+1)"]
    end

    LA --> LORM
    LA --> LVal
    LA --> LExp
    LA --> LAud
    LI --> LApi
    LV --> LApi
    LQ --> LORM
    LVal --> LORM
    LAud --> LORM
```

### 1. Sovereign & Zero 3rd-Party Dependencies
- **No `firebase/php-jwt`**: `LiteApi\Security\Jwt` implements zero-dependency HMAC-SHA256 JWT with timing-attack prevention.
- **No `phpoffice/phpspreadsheet`** (saves 60MB RAM): `LiteExport` streams native OpenXML `.xlsx` zip files in < 16MB RAM.
- **No `bladeone` / `twig`**: `LiteView` compiles directly into native PHP code executed by OpCache.
- **No external Redis required**: `LiteQueue` provides atomic job reservation on SQLite, MySQL, or PostgreSQL.

### 2. Enterprise Security Hardened
- **Stateless HMAC-SHA256 CSRF**: Session-lock-free, multi-server ready (`LiteAdmin`, `LiteView`).
- **Mass-Assignment Immunity**: Automatic protection for readonly entity properties.
- **SQL Injection Prevention**: 100% Prepared Statements + Sort Whitelisting.
- **Spreadsheet Formula Injection Defense**: Automatic sanitization against CWE-1236 DDE exploits (`LiteExport`).
- **Timing Attack Mitigation**: Constant-time `hash_equals` checks for JWT and CSRF.

---

## 🚀 Quick Starter & Application Skeleton

Clone and run the complete application skeleton in seconds with pure Git repositories:

```bash
# 1. Clone the repository
git clone https://github.com/kzxl/LitePlatform.git my-lite-app
cd my-lite-app

# 2. Install dependencies (Pulls directly from sovereign Git repos)
composer install

# 3. Inspect ecosystem status & loaded providers
php bin/lite status

# 4. Start local development server
php bin/lite serve --port=8000
```

Open your browser at:
- **Web / HTMX Showcase**: `http://localhost:8000/`
- **Auto-CRUD Dashboard**: `http://localhost:8000/admin`
- **System Health API**: `http://localhost:8000/api/status`
- **REST Entities API**: `http://localhost:8000/api/users`

---

## 🛠️ Developer CLI Scaffolding (`bin/lite`)

`LitePlatform` includes a zero-dependency pure PHP scaffolding toolkit:

| Command | Purpose |
| :--- | :--- |
| `php bin/lite status` | Real-time system health, PHP runtime, and loaded Lite components. |
| `php bin/lite serve` | Launch PHP local dev server (`--host=127.0.0.1`, `--port=8000`). |
| `php bin/lite routes` | Display all registered application routes and handlers. |
| `php bin/lite make:controller <Name>` | Scaffold a new PSR-15 Controller. |
| `php bin/lite make:entity <Name>` | Scaffold a LiteORM Entity with LiteAdmin attributes. |
| `php bin/lite make:dto <Name>` | Scaffold a typed DTO with `LiteValidate` rules. |
| `php bin/lite make:job <Name>` | Scaffold a `LiteQueue` asynchronous background Job. |

---

## 📄 License

MIT License — see [LICENSE](LICENSE) for details.

