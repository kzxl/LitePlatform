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
            --bg-color: #faf8f5;
            --surface-color: #ffffff;
            --surface-hover: #f3eee7;
            --border-color: #e6dfd5;
            --text-primary: #1c1917;
            --text-secondary: #57534e;
            --accent: #8c2d3b;
            --accent-hover: #6f212d;
            --success: #15803d;
            --danger: #b91c1c;
            --radius-base: 4px;
            --card-ratio: 3/4;
        }
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; background: var(--bg-color); color: var(--text-primary); line-height: 1.65; min-height: 100vh; display: flex; flex-direction: column; }
        header.editorial-header { background: var(--surface-color); border-bottom: 1px solid var(--border-color); padding: 1.25rem 1.25rem 0.9rem; }
        .nav-container { max-width: 1140px; margin: 0 auto; display: flex; flex-direction: column; align-items: center; gap: 0.85rem; }
        .brand { text-decoration: none; color: var(--text-primary); font-family: Georgia, Cambria, 'Times New Roman', serif; font-size: 1.6rem; font-weight: 700; letter-spacing: 0.06em; text-transform: uppercase; }
        .nav-links { display: flex; align-items: center; justify-content: center; gap: 1.5rem; list-style: none; flex-wrap: wrap; }
        .nav-links a { color: var(--text-secondary); text-decoration: none; font-size: 0.84rem; font-weight: 600; text-transform: uppercase; letter-spacing: 0.08em; }
        .nav-links a:hover { color: var(--accent); }
        main { flex: 1; max-width: 1140px; width: 100%; margin: 0 auto; padding: 2.75rem 1.25rem; }
        .card { background: var(--surface-color); border: 1px solid var(--border-color); border-radius: var(--radius-base); padding: 1.65rem; }
        .btn { display: inline-flex; align-items: center; justify-content: center; gap: 0.45rem; padding: 0.65rem 1.35rem; border-radius: var(--radius-base); font-weight: 600; font-size: 0.86rem; letter-spacing: 0.04em; text-transform: uppercase; text-decoration: none; cursor: pointer; border: 1px solid var(--border-color); background: var(--surface-color); color: var(--text-primary); }
        .btn:hover { background: var(--surface-hover); }
        .btn-primary { background: var(--accent); border-color: var(--accent); color: #ffffff; }
        .btn-primary:hover { background: var(--accent-hover); border-color: var(--accent-hover); }
        .btn-outline { background: transparent; }
        .section-head { display: flex; align-items: baseline; justify-content: space-between; margin-bottom: 1.25rem; padding-bottom: 0.5rem; border-bottom: 1px solid var(--border-color); }
        .section-head h2 { font-family: Georgia, Cambria, serif; font-size: 1.45rem; font-weight: 700; }
        .section-head a { font-size: 0.82rem; font-weight: 600; text-transform: uppercase; letter-spacing: 0.06em; color: var(--accent); text-decoration: none; }
        footer.site-footer { background: var(--surface-color); border-top: 1px solid var(--border-color); padding: 2.25rem 1.25rem; color: var(--text-secondary); font-size: 0.85rem; }
        .footer-inner { max-width: 1140px; margin: 0 auto; display: flex; justify-content: space-between; align-items: center; gap: 1rem; flex-wrap: wrap; }
        .footer-meta { display: flex; gap: 1.25rem; flex-wrap: wrap; }
        .footer-meta a { color: var(--text-secondary); text-decoration: none; }
    </style>
    {!! $themeCss ?? '' !!}
</head>
<body data-theme="editorial">
    <header class="editorial-header">
        <nav class="nav-container" aria-label="Main Navigation">
            <a href="/" class="brand">{{ ($settings ?? null) ? $settings->get('site_name', $appName ?? 'LitePlatform') : ($appName ?? 'LitePlatform') }}</a>
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
            </ul>
        </nav>
    </header>
    <main>
        @yield('content')
    </main>
    <footer class="site-footer">
        <div class="footer-inner">
            <div>&copy; {{ date('Y') }} <strong>{{ ($settings ?? null) ? $settings->get('site_name', $appName ?? 'LitePlatform') : ($appName ?? 'LitePlatform') }}</strong></div>
            <div class="footer-meta">
                @if(($settings ?? null) && $settings->get('hotline') !== '')
                <span>Hotline: <strong>{{ $settings->get('hotline') }}</strong></span>
                @endif
                <a href="/sitemap.xml">Sitemap</a>
            </div>
        </div>
    </footer>
</body>
</html>
