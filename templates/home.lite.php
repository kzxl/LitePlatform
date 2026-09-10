@extends('layouts.app')

@section('content')
<div style="display: flex; flex-direction: column; gap: 2.5rem;">
    <!-- Hero Section -->
    <div style="text-align: center; max-width: 800px; margin: 0 auto;">
        <h1 style="font-size: 2.75rem; font-weight: 800; line-height: 1.2; margin-bottom: 1rem; background: linear-gradient(135deg, #ffffff 0%, #8b949e 100%); -webkit-background-clip: text; -webkit-text-fill-color: transparent;">
            Build Any Web App in Milliseconds.
        </h1>
        <p style="font-size: 1.15rem; color: var(--text-secondary); margin-bottom: 1.75rem;">
            A sovereign, modular, zero-bloat Full-Stack skeleton powered by PHP 8.2+, Slim 4, and the 10-library Lite ecosystem.
        </p>
        <div style="display: flex; justify-content: center; gap: 1rem; flex-wrap: wrap;">
            <a href="/admin" class="btn btn-primary">Open Admin Dashboard</a>
            <a href="/api/status" class="btn btn-outline">Test REST API</a>
            <button class="btn btn-outline" hx-get="/fragment/metrics" hx-target="#metrics-container" hx-swap="outerHTML">
                ⚡ HTMX Live Refresh
            </button>
        </div>
    </div>

    <!-- Live Metrics Box (Demonstrating HTMX @fragment) -->
    <div id="metrics-container">
        @fragment('metrics')
        <div class="card" style="border-left: 4px solid var(--accent);">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1rem;">
                <h3 style="font-size: 1.1rem; font-weight: 600;">⚡ Real-time Runtime Metrics</h3>
                <span class="badge badge-success">● Active (HTMX Fragment)</span>
            </div>
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 1rem;">
                <div>
                    <div style="font-size: 0.8rem; color: var(--text-secondary); text-transform: uppercase;">PHP Version</div>
                    <div style="font-size: 1.25rem; font-weight: 700; color: var(--accent);">{{ $phpVersion }}</div>
                </div>
                <div>
                    <div style="font-size: 0.8rem; color: var(--text-secondary); text-transform: uppercase;">Memory Allocated</div>
                    <div style="font-size: 1.25rem; font-weight: 700; color: var(--success);">{{ $memoryUsage }}</div>
                </div>
                <div>
                    <div style="font-size: 0.8rem; color: var(--text-secondary); text-transform: uppercase;">Peak Memory</div>
                    <div style="font-size: 1.25rem; font-weight: 700; color: var(--text-primary);">{{ $memoryPeak }}</div>
                </div>
                <div>
                    <div style="font-size: 0.8rem; color: var(--text-secondary); text-transform: uppercase;">Server Timestamp</div>
                    <div style="font-size: 1.05rem; font-weight: 600; color: var(--text-secondary);">{{ $timestamp }}</div>
                </div>
            </div>
        </div>
        @endfragment
    </div>

    <!-- 10 Sovereign Libraries Matrix -->
    <div>
        <h2 style="font-size: 1.5rem; font-weight: 700; margin-bottom: 1.25rem;">Ecosystem Status (Opt-in / Opt-out)</h2>
        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(260px, 1fr)); gap: 1.25rem;">
            @foreach($components as $name => $active)
            <div class="card" style="display: flex; flex-direction: column; justify-content: space-between;">
                <div>
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.5rem;">
                        <span style="font-weight: 700; font-size: 1.05rem;">{{ $name }}</span>
                        @if($active)
                            <span class="badge badge-success">Loaded</span>
                        @else
                            <span class="badge badge-muted">Disabled</span>
                        @endif
                    </div>
                    <p style="font-size: 0.85rem; color: var(--text-secondary);">
                        Zero external vendor lock-in. Configured via Service Provider in <span class="code-inline">config/app.php</span>.
                    </p>
                </div>
            </div>
            @endforeach
        </div>
    </div>

    <!-- Scaffolding Guide -->
    <div class="card" style="background: rgba(22, 27, 34, 0.5);">
        <h3 style="font-size: 1.15rem; font-weight: 700; margin-bottom: 0.75rem;">Instant CLI Scaffolding</h3>
        <p style="color: var(--text-secondary); margin-bottom: 1rem; font-size: 0.9rem;">
            Generate controllers, entities, validation DTOs, and background jobs with zero runtime overhead:
        </p>
        <pre style="background: #0d1117; padding: 1rem; border-radius: 8px; border: 1px solid var(--border-color); font-family: 'JetBrains Mono', monospace; font-size: 0.85rem; color: #79c0ff; overflow-x: auto;">
php bin/lite status
php bin/lite make:controller OrderController
php bin/lite make:entity Order
php bin/lite make:dto CreateOrderRequest
php bin/lite make:job SendInvoiceJob
php bin/lite serve --port=8000
        </pre>
    </div>
</div>
@endsection
