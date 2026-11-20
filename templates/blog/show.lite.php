@extends('layouts.app')

@section('content')
<article class="card" style="max-width: 820px; margin: 0 auto; padding: 2rem;">
    <nav aria-label="Breadcrumb" style="font-size: 0.85rem; color: var(--text-secondary); margin-bottom: 1rem;">
        <a href="/" style="color: var(--accent); text-decoration: none;">@lang('nav.overview')</a> /
        <a href="/blog" style="color: var(--accent); text-decoration: none;">Blog</a>
    </nav>

    <h1 style="font-size: 2rem; line-height: 1.25; margin-bottom: 1rem;">{{ $post['title'] }}</h1>

    @if($post['published_at'] ?? null)
    <p style="font-size: 0.85rem; color: var(--text-secondary); margin-bottom: 1.5rem;">
        {{ $post['published_at'] }}
    </p>
    @endif

    <div class="article-body" style="line-height: 1.8; color: var(--text-primary);">
        @sanitize($post['content'])
    </div>
</article>
@endsection
