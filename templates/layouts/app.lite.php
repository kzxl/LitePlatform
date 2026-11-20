<!DOCTYPE html>
<html lang="{{ $locale ?? 'vi' }}">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    @if($seo ?? null)
    {!! $seo->renderHeadTags() !!}
    @else
    <title>{{ $title ?? 'LitePlatform' }}</title>
    @endif
    @if($settings ?? null)
    {!! $settings->renderTrackingHead() !!}
    @endif
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
    <script src="https://unpkg.com/htmx.org@2.0.2"></script>
    <style>
        :root {
            --bg-color: #0d1117;
            --surface-color: #161b22;
            --surface-hover: #21262d;
            --border-color: #30363d;
            --text-primary: #f0f6fc;
            --text-secondary: #8b949e;
            --accent: #58a6ff;
            --accent-gradient: linear-gradient(135deg, #388bfd 0%, #79c0ff 100%);
            --success: #3fb950;
            --warning: #d29922;
            --danger: #f85149;
        }

        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif;
            background-color: var(--bg-color);
            color: var(--text-primary);
            line-height: 1.6;
            min-height: 100vh;
            display: flex;
            flex-direction: column;
        }

        header {
            border-bottom: 1px solid var(--border-color);
            background-color: rgba(22, 27, 34, 0.8);
            backdrop-filter: blur(12px);
            position: sticky;
            top: 0;
            z-index: 100;
        }

        .nav-container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 1rem 1.5rem;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }

        .brand {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            text-decoration: none;
            color: var(--text-primary);
            font-weight: 700;
            font-size: 1.25rem;
        }

        .brand-badge {
            background: var(--accent-gradient);
            color: #0d1117;
            font-size: 0.75rem;
            font-weight: 700;
            padding: 0.2rem 0.5rem;
            border-radius: 6px;
            letter-spacing: 0.05em;
        }

        .nav-links {
            display: flex;
            gap: 1.5rem;
            list-style: none;
        }

        .nav-links a {
            color: var(--text-secondary);
            text-decoration: none;
            font-size: 0.9rem;
            font-weight: 500;
            transition: color 0.2s ease;
        }

        .nav-links a:hover {
            color: var(--accent);
        }

        main {
            flex: 1;
            max-width: 1200px;
            width: 100%;
            margin: 0 auto;
            padding: 2.5rem 1.5rem;
        }

        footer {
            border-top: 1px solid var(--border-color);
            padding: 1.5rem;
            text-align: center;
            color: var(--text-secondary);
            font-size: 0.85rem;
        }

        .btn {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.6rem 1.2rem;
            border-radius: 8px;
            font-weight: 600;
            font-size: 0.9rem;
            text-decoration: none;
            cursor: pointer;
            transition: all 0.2s ease;
            border: 1px solid transparent;
        }

        .btn-primary {
            background: var(--accent);
            color: #0d1117;
        }

        .btn-primary:hover {
            background: #79c0ff;
            transform: translateY(-1px);
        }

        .btn-outline {
            background: transparent;
            border-color: var(--border-color);
            color: var(--text-primary);
        }

        .btn-outline:hover {
            background: var(--surface-hover);
            border-color: var(--text-secondary);
        }

        .badge {
            display: inline-flex;
            align-items: center;
            gap: 0.35rem;
            padding: 0.25rem 0.65rem;
            border-radius: 999px;
            font-size: 0.75rem;
            font-weight: 600;
        }

        .badge-success {
            background-color: rgba(63, 185, 80, 0.15);
            color: var(--success);
            border: 1px solid rgba(63, 185, 80, 0.3);
        }

        .badge-muted {
            background-color: rgba(139, 148, 158, 0.15);
            color: var(--text-secondary);
            border: 1px solid rgba(139, 148, 158, 0.3);
        }

        .card {
            background-color: var(--surface-color);
            border: 1px solid var(--border-color);
            border-radius: 12px;
            padding: 1.5rem;
            transition: border-color 0.2s ease;
        }

        .card:hover {
            border-color: #484f58;
        }

        .code-inline {
            font-family: 'JetBrains Mono', monospace;
            background: rgba(110, 118, 129, 0.2);
            padding: 0.2em 0.4em;
            border-radius: 6px;
            font-size: 0.85em;
        }

        .lang-switcher {
            display: inline-flex;
            align-items: center;
            border: 1px solid var(--border-color);
            border-radius: 6px;
            overflow: hidden;
        }

        .lang-switcher a {
            padding: 0.25rem 0.55rem;
            font-size: 0.75rem;
            font-weight: 600;
            text-transform: uppercase;
            color: var(--text-secondary);
            text-decoration: none;
        }

        .lang-switcher a.active {
            background: var(--accent);
            color: #0d1117;
        }
    </style>
</head>
<body>
    <header>
        <nav class="nav-container">
            <a href="/" class="brand">
                <span>⚡ LitePlatform</span>
                <span class="brand-badge">PHP 8.2+</span>
            </a>
            <ul class="nav-links" style="align-items: center;">
                <li><a href="/">@lang('nav.overview')</a></li>
                <li><a href="/admin" target="_blank">@lang('nav.admin_dashboard')</a></li>
                <li><a href="/api/status" target="_blank">@lang('nav.status_api')</a></li>
                <li><a href="/api/users" target="_blank">@lang('nav.users_api')</a></li>
                @if($supportedLocales ?? null)
                <li>
                    <div class="lang-switcher" aria-label="Language Switcher">
                        @foreach($supportedLocales as $loc)
                        <a href="/lang/{{ $loc }}?redirect=/" class="{{ ($locale ?? 'vi') === $loc ? 'active' : '' }}" hreflang="{{ $loc }}">{{ strtoupper($loc) }}</a>
                        @endforeach
                    </div>
                </li>
                @endif
            </ul>
        </nav>
    </header>

    <main>
        @yield('content')
    </main>

    <footer>
        <p>{!! $t('footer.powered_by') !!}</p>
    </footer>
</body>
</html>
