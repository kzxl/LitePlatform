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
    <style>
        :root {
            --bg-color: #f8fafc;
            --surface-color: #ffffff;
            --surface-hover: #f1f5f9;
            --border-color: #e2e8f0;
            --text-primary: #0f172a;
            --text-secondary: #475569;
            --accent: #0f62fe;
            --accent-hover: #0353e9;
            --success: #15803d;
            --danger: #b91c1c;
        }

        * { box-sizing: border-box; margin: 0; padding: 0; }

        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;
            background-color: var(--bg-color);
            color: var(--text-primary);
            line-height: 1.6;
            min-height: 100vh;
            display: flex;
            flex-direction: column;
        }

        header.site-header {
            background-color: var(--surface-color);
            border-bottom: 1px solid var(--border-color);
            position: sticky;
            top: 0;
            z-index: 100;
        }

        .nav-container {
            max-width: 1140px;
            margin: 0 auto;
            padding: 0.9rem 1.25rem;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 1rem;
            flex-wrap: wrap;
        }

        .brand {
            display: inline-flex;
            align-items: center;
            gap: 0.55rem;
            text-decoration: none;
            color: var(--text-primary);
            font-weight: 800;
            font-size: 1.15rem;
            letter-spacing: -0.02em;
        }

        .brand-mark {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 28px;
            height: 28px;
            border-radius: 6px;
            background: var(--accent);
            color: #ffffff;
            font-size: 0.85rem;
            font-weight: 800;
        }

        .nav-links {
            display: flex;
            align-items: center;
            gap: 1.25rem;
            list-style: none;
            flex-wrap: wrap;
        }

        .nav-links a {
            color: var(--text-secondary);
            text-decoration: none;
            font-size: 0.92rem;
            font-weight: 500;
        }

        .nav-links a:hover { color: var(--accent); }

        .lang-switcher {
            display: inline-flex;
            border: 1px solid var(--border-color);
            border-radius: 6px;
            overflow: hidden;
            background: var(--bg-color);
        }

        .lang-switcher a {
            padding: 0.22rem 0.55rem;
            font-size: 0.75rem;
            font-weight: 700;
            text-transform: uppercase;
            color: var(--text-secondary);
            text-decoration: none;
        }

        .lang-switcher a.active {
            background: var(--accent);
            color: #ffffff;
        }

        main {
            flex: 1;
            max-width: 1140px;
            width: 100%;
            margin: 0 auto;
            padding: 2.5rem 1.25rem;
        }

        .card {
            background: var(--surface-color);
            border: 1px solid var(--border-color);
            border-radius: 10px;
            padding: 1.5rem;
            box-shadow: 0 1px 2px rgba(15, 23, 42, 0.04);
        }

        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 0.45rem;
            padding: 0.62rem 1.2rem;
            border-radius: 7px;
            font-weight: 600;
            font-size: 0.92rem;
            text-decoration: none;
            cursor: pointer;
            border: 1px solid var(--border-color);
            background: var(--surface-color);
            color: var(--text-primary);
            transition: background 0.15s ease, border-color 0.15s ease;
        }

        .btn:hover { background: var(--surface-hover); }

        .btn-primary {
            background: var(--accent);
            border-color: var(--accent);
            color: #ffffff;
        }

        .btn-primary:hover {
            background: var(--accent-hover);
            border-color: var(--accent-hover);
        }

        .btn-outline { background: var(--surface-color); }

        .section-head {
            display: flex;
            align-items: baseline;
            justify-content: space-between;
            margin-bottom: 1.15rem;
            gap: 1rem;
        }

        .section-head h2 {
            font-size: 1.35rem;
            font-weight: 700;
            letter-spacing: -0.015em;
        }

        .section-head a {
            font-size: 0.88rem;
            font-weight: 600;
            color: var(--accent);
            text-decoration: none;
        }

        footer.site-footer {
            background: var(--surface-color);
            border-top: 1px solid var(--border-color);
            padding: 2rem 1.25rem;
            color: var(--text-secondary);
            font-size: 0.88rem;
        }

        .footer-inner {
            max-width: 1140px;
            margin: 0 auto;
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 1rem;
            flex-wrap: wrap;
        }

        .footer-meta { display: flex; gap: 1.25rem; flex-wrap: wrap; }
        .footer-meta a { color: var(--text-secondary); text-decoration: none; }
        .footer-meta a:hover { color: var(--accent); }
    </style>
    {!! $themeCss ?? '' !!}
</head>
<body>
    <header class="site-header">
        <nav class="nav-container" aria-label="Main Navigation">
            <a href="/" class="brand">
                <span class="brand-mark">L</span>
                <span>{{ ($settings ?? null) ? $settings->get('site_name', $appName ?? 'LitePlatform') : ($appName ?? 'LitePlatform') }}</span>
            </a>
            <ul class="nav-links">
                <li><a href="/">@lang('nav.overview')</a></li>
                @if($moduleNavLinks ?? null)
                    @foreach($moduleNavLinks as $ml)
                    <li><a href="{{ $ml['href'] }}">{{ $ml['label'] }}</a></li>
                    @endforeach
                @endif
                <li><a href="/blog">@lang('nav.blog')</a></li>
                @if($navPages ?? null)
                    @foreach($navPages as $np)
                    <li><a href="/{{ $np['slug'] }}">{{ $np['title'] }}</a></li>
                    @endforeach
                @endif
                <li><a href="/contact">@lang('nav.contact')</a></li>
                <li><a href="/admin">@lang('nav.admin_dashboard')</a></li>
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

    <footer class="site-footer">
        <div class="footer-inner">
            <div>
                <p>&copy; {{ date('Y') }} <strong>{{ ($settings ?? null) ? $settings->get('site_name', $appName ?? 'LitePlatform') : ($appName ?? 'LitePlatform') }}</strong></p>
                <p style="font-size: 0.82rem; margin-top: 0.25rem;">{!! $t('footer.powered_by') !!}</p>
            </div>
            <div class="footer-meta">
                @if(($settings ?? null) && $settings->get('hotline') !== '')
                <span>Hotline: <strong>{{ $settings->get('hotline') }}</strong></span>
                @endif
                @if(($settings ?? null) && $settings->get('email') !== '')
                <a href="mailto:{{ $settings->get('email') }}">{{ $settings->get('email') }}</a>
                @endif
                <a href="/sitemap.xml">Sitemap</a>
            </div>
        </div>
    </footer>
</body>
</html>
