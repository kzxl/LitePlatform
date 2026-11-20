@extends('layouts.app')

@section('content')
<article class="card" style="max-width: 860px; margin: 0 auto; padding: 2.25rem;">
    <nav aria-label="Breadcrumb" style="font-size: 0.85rem; color: var(--text-secondary); margin-bottom: 1rem;">
        <a href="/" style="color: var(--accent); text-decoration: none;">@lang('nav.overview')</a> /
        <span>{{ $page['title'] }}</span>
    </nav>

    <h1 style="font-size: 2rem; line-height: 1.25; margin-bottom: 1.25rem;">{{ $page['title'] }}</h1>

    @if($page['featured_image'] ?? null)
    <div style="margin-bottom: 1.5rem;">
        <img src="{{ $page['featured_image'] }}" alt="{{ $page['title'] }}" loading="lazy" style="max-width: 100%; height: auto; border-radius: 8px;">
    </div>
    @endif

    <div class="article-body" style="line-height: 1.8; color: var(--text-primary);">
        @sanitize($page['content'])
    </div>
</article>
@endsection
