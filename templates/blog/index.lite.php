@extends('layouts.app')

@section('content')
<div style="display: flex; flex-direction: column; gap: 1.5rem;">
    <header style="border: none; background: transparent; backdrop-filter: none; position: static;">
        <h1 style="font-size: 2rem; font-weight: 800; margin-bottom: 0.5rem;">Blog</h1>
    </header>

    @if(empty($posts))
        <div class="card">
            <p style="color: var(--text-secondary);">No published articles yet.</p>
        </div>
    @else
        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 1.25rem;">
            @foreach($posts as $post)
            <article class="card" style="display: flex; flex-direction: column; gap: 0.75rem;">
                <h2 style="font-size: 1.2rem; line-height: 1.35;">
                    <a href="/blog/{{ $post['slug'] }}" style="color: var(--text-primary); text-decoration: none;">
                        {{ $post['title'] }}
                    </a>
                </h2>
                @if($post['excerpt'] ?? null)
                <p style="color: var(--text-secondary); font-size: 0.92rem;">{{ $post['excerpt'] }}</p>
                @endif
                <a href="/blog/{{ $post['slug'] }}" class="btn btn-outline" style="align-self: flex-start;">Read &rarr;</a>
            </article>
            @endforeach
        </div>
    @endif
</div>
@endsection
