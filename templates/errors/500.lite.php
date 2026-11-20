<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title>{{ $status ?? 500 }} — {{ $title ?? 'Server Error' }} | {{ $appName ?? 'LitePlatform' }}</title>
    <style>
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: #0d1117;
            color: #f0f6fc;
            margin: 0;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 1.5rem;
        }
        .box {
            max-width: 560px;
            width: 100%;
            background: #161b22;
            border: 1px solid #30363d;
            border-radius: 12px;
            padding: 2.25rem;
            text-align: center;
        }
        .code { font-size: 3.25rem; font-weight: 800; color: #f85149; line-height: 1; margin-bottom: 0.75rem; }
        h1 { font-size: 1.35rem; margin: 0 0 0.75rem; }
        p { color: #8b949e; margin: 0 0 1.25rem; line-height: 1.6; }
        pre {
            text-align: left;
            background: #0d1117;
            border: 1px solid #30363d;
            border-radius: 8px;
            padding: 0.85rem;
            color: #ff7b72;
            font-size: 0.8rem;
            overflow-x: auto;
            margin-bottom: 1.25rem;
        }
        a {
            display: inline-block;
            padding: 0.65rem 1.25rem;
            background: #58a6ff;
            color: #0d1117;
            text-decoration: none;
            border-radius: 8px;
            font-weight: 600;
        }
    </style>
</head>
<body>
    <main class="box">
        <div class="code">{{ $status ?? 500 }}</div>
        <h1>{{ $title ?? 'Internal Server Error' }}</h1>
        <p>{{ $message ?? 'An unexpected error occurred. Please try again later.' }}</p>
        @if($debugDetail ?? null)
        <pre>{{ $debugDetail }}</pre>
        @endif
        <a href="/">Back to Home</a>
    </main>
</body>
</html>
