@extends('layouts.app')

@section('content')
<div style="display: flex; flex-direction: column; gap: 2.75rem;">
    @if(($renderedSections ?? '') !== '')
        {!! $renderedSections !!}
    @else
        <!-- Clean Enterprise Starter Hero -->
        <section class="card" style="padding: 3rem 2rem; text-align: center;">
            <h1 style="font-size: 2.25rem; font-weight: 800; line-height: 1.2; letter-spacing: -0.025em; margin-bottom: 0.85rem; color: var(--text-primary);">
                @lang('home.hero_title')
            </h1>
            <p style="font-size: 1.05rem; color: var(--text-secondary); max-width: 680px; margin: 0 auto 1.75rem;">
                {{ ($tagline ?? '') !== '' ? $tagline : $t('home.hero_subtitle') }}
            </p>
            <div style="display: flex; justify-content: center; gap: 0.85rem; flex-wrap: wrap;">
                <a href="/contact" class="btn btn-primary">@lang('nav.contact')</a>
                <a href="/blog" class="btn btn-outline">@lang('nav.blog')</a>
                <a href="/admin" class="btn btn-outline">@lang('home.cta_admin')</a>
            </div>
        </section>
    @endif

    <!-- Optional Catalog / Products Section (Hidden unless enabled in /admin/settings or populated) -->
    @if($showHomeProducts ?? false)
    <section>
        <div class="section-head">
            <h2>@lang('home.featured_products')</h2>
            <a href="/admin/product">@lang('home.manage_catalog') &rarr;</a>
        </div>

        @if(empty($featuredProducts))
            <div class="card" style="display: flex; justify-content: space-between; align-items: center; gap: 1rem; flex-wrap: wrap;">
                <p style="color: var(--text-secondary);">@lang('home.empty_products')</p>
                <a href="/admin/product/create" class="btn btn-outline">@lang('home.create_first_product')</a>
            </div>
        @else
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap: 1.25rem;">
                @foreach($featuredProducts as $prod)
                <div class="card" style="display: flex; flex-direction: column; justify-content: space-between; gap: 0.65rem;">
                    <div>
                        <h3 style="font-size: 1.05rem; font-weight: 700; margin-bottom: 0.35rem;">{{ $prod['name'] }}</h3>
                        <div style="font-size: 1.1rem; font-weight: 800; color: var(--accent);">
                            {{ number_format((float)$prod['price'], 0, ',', '.') }}
                        </div>
                    </div>
                    <a href="/contact" class="btn btn-outline" style="width: 100%;">@lang('nav.contact')</a>
                </div>
                @endforeach
            </div>
        @endif
    </section>
    @endif
</div>
@endsection
