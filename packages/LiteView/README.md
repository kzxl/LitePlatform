# LiteView

[![Latest Version](https://img.shields.io/github/v/release/kzxl/LiteView?label=version&color=blue)](https://github.com/kzxl/LiteView/releases)
[![PHP Version](https://img.shields.io/badge/php-%3E%3D8.2-8892BF.svg)](https://php.net)
[![License: MIT](https://img.shields.io/badge/License-MIT-green.svg)](LICENSE)

[![PHP 8.2+](https://img.shields.io/badge/php-8.2%2B-blue.svg)](https://www.php.net/)
[![License: MIT](https://img.shields.io/badge/License-MIT-blue.svg)](LICENSE)
[![Tests](https://img.shields.io/badge/tests-passing-brightgreen.svg)]()

Ultra-fast compiled template engine for PHP 8.2+ with Blade-like directives, layout inheritance, and native HTMX partial fragment support. Zero dependencies outside standard PHP.

---

## Key Features

- **OpCache-Native Speed**: Compiles templates once into pure native PHP code stored in a cache directory. Subsequent executions run directly from PHP's bytecode cache at tens of thousands of requests per second.
- **Blade-Style Directives**:
  - Auto-escaped echo: `{{ $name }}` (XSS safe via `htmlspecialchars`)
  - Raw echo: `{!! $html !!}`
  - Comments: `{{-- Secret --}}` (completely stripped at compile-time)
  - Control flow: `@if`, `@elseif`, `@else`, `@endif`, `@unless`, `@isset`, `@empty`
  - Loops: `@foreach`, `@for`, `@while`
  - Forms: `@csrf`, `@method('PUT')`
  - Template inheritance: `@extends('layouts.main')`, `@section('content')`, `@yield('content')`, `@include('partials.card')`
- **Anonymous Components & Slots**:
  - Declarative tag syntax: `<x-card title="Profile" :active="true"> ... </x-card>`
  - Named slots: `<x-slot:header><h3>Header</h3></x-slot:header>` or `<x-slot name="header">`
  - Self-closing components: `<x-icon name="check" class="text-green" />`
  - Classic Blade components: `@component('components.card', [...]) ... @endcomponent` and `@slot('title')`
- **Native HTMX Fragment & Out-Of-Band (OOB) Swaps**:
  - `@fragment('user-row') ... @endfragment`: Tag any section within a full page view.
  - `$engine->renderFragment('users', 'user-row', $data)`: Renders **only** the specified fragment.
  - **HTMX Out-of-Band (OOB) Swaps**: `$engine->renderOob('dashboard', ['feed' => true, 'cart' => 'outerHTML:#cart-count'])` returning multiple target updates with `hx-swap-oob` in a single response.
- **First-Class HTMX Utilities (`LiteView\Htmx`)**:
  - Detect HTMX requests (`Htmx::isHtmx()`, `Htmx::isBoosted()`)
  - Inspect headers (`Htmx::getTarget()`, `Htmx::getTrigger()`)
  - Mutate responses (`Htmx::trigger()`, `Htmx::redirect()`, `Htmx::refresh()`, `Htmx::reswap()`, `Htmx::retarget()`, `Htmx::oob()`)
- **PSR-7 & Slim 4 Ready**:
  - `$engine->renderResponse($response, 'users.index', $data)`
  - `$engine->renderFragmentResponse($response, 'users.index', 'table-body', $data)`
  - `$engine->renderOobResponse($response, 'dashboard', ['feed' => true, 'cart' => true])`

---

## 📦 Installation

### Option 1: Standard Composer (via Packagist)
```bash
composer require kzxl/lite-view
```

### Option 2: Direct from Git Repository (VCS)
To pull directly from the official GitHub repository without waiting for Packagist synchronization, add the VCS repository to your project's `composer.json`:

```json
{
    "repositories": [
        {
            "type": "vcs",
            "url": "https://github.com/kzxl/LiteView.git"
        }
    ],
    "require": {
        "kzxl/lite-view": "^1.2.0"
    }
}
```
Or configure via CLI:
```bash
composer config repositories.lite-view vcs https://github.com/kzxl/LiteView.git
composer require kzxl/lite-view:^1.2.0
```

### Option 3: Local Path Repository (Monorepo / Development)
For local development where changes should reflect immediately via symlink:
```json
{
    "repositories": [
        {
            "type": "path",
            "url": "../libs/LiteView",
            "options": {
                "symlink": true
            }
        }
    ],
    "require": {
        "kzxl/lite-view": "@dev"
    }
}
```

---

## Usage Example

### 1. Master Layout (`views/layouts/app.lite.php`)

```html
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>@yield('title', 'My Application')</title>
    <!-- HTMX CDN -->
    <script src="https://unpkg.com/htmx.org@2.0.4"></script>
</head>
<body>
    <header><h1>Dashboard</h1></header>
    <main>
        @yield('content')
    </main>
</body>
</html>
```

### 2. Child View with HTMX Fragment (`views/users.lite.php`)

```html
@extends('layouts.app')

@section('title') Users List @endsection

@section('content')
    <input type="text" name="q" 
           hx-get="/users/search" 
           hx-target="#user-rows" 
           hx-trigger="keyup changed delay:300ms">

    <table>
        <thead><tr><th>Name</th><th>Email</th></tr></thead>
        <tbody id="user-rows">
            @fragment('rows')
                @foreach($users as $user)
                    <tr>
                        <td>{{ $user->name }}</td>
                        <td>{{ $user->email }}</td>
                    </tr>
                @endforeach
            @endfragment
        </tbody>
    </table>
@endsection
```

### 3. Slim 4 Controller

```php
use LiteView\ViewEngine;
use LiteView\Htmx;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

$engine = new ViewEngine(__DIR__ . '/views', __DIR__ . '/cache');

$app->get('/users', function (Request $request, Response $response) use ($engine, $em) {
    $users = $em->findAll(User::class);

    // If request comes from HTMX search input, render ONLY the table rows!
    if (Htmx::isHtmx($request)) {
        return $engine->renderFragmentResponse($response, 'users', 'rows', ['users' => $users]);
    }

    // Otherwise render full HTML page with layout
    return $engine->renderResponse($response, 'users', ['users' => $users]);
});
```

---

## Testing

```bash
composer test
```

Runs test suite using PHPUnit 11 with 100% pass rate.

---

## License

MIT License — see [LICENSE](LICENSE) for details.

