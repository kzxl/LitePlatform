@extends('layouts.app')

@section('content')
<div class="card" style="max-width: 640px; margin: 0 auto; padding: 2rem;">
    <h1 style="font-size: 1.75rem; font-weight: 800; margin-bottom: 0.5rem;">@lang('contact.title')</h1>
    <p style="color: var(--text-secondary); margin-bottom: 1.5rem;">@lang('contact.subtitle')</p>

    @if($success ?? null)
    <div style="margin-bottom: 1.25rem; padding: 0.85rem 1rem; border-radius: 8px; background: rgba(63, 185, 80, 0.14); border: 1px solid rgba(63, 185, 80, 0.35); color: var(--success);">
        {{ $success }}
    </div>
    @endif

    @if($error ?? null)
    <div style="margin-bottom: 1.25rem; padding: 0.85rem 1rem; border-radius: 8px; background: rgba(248, 81, 73, 0.14); border: 1px solid rgba(248, 81, 73, 0.35); color: #ff7b72;">
        {{ $error }}
    </div>
    @endif

    <form method="POST" action="/contact" style="display: flex; flex-direction: column; gap: 1rem;">
        @csrf
        @honeypot
        <div>
            <label for="name" style="display:block; font-size: 0.85rem; font-weight: 600; margin-bottom: 0.4rem;">@lang('contact.name')</label>
            <input type="text" id="name" name="name" value="{{ $old['name'] ?? '' }}" required
                   style="width: 100%; padding: 0.7rem 0.9rem; background: #0d1117; border: 1px solid var(--border-color); border-radius: 8px; color: var(--text-primary);">
        </div>
        <div>
            <label for="email" style="display:block; font-size: 0.85rem; font-weight: 600; margin-bottom: 0.4rem;">@lang('contact.email')</label>
            <input type="email" id="email" name="email" value="{{ $old['email'] ?? '' }}" required
                   style="width: 100%; padding: 0.7rem 0.9rem; background: #0d1117; border: 1px solid var(--border-color); border-radius: 8px; color: var(--text-primary);">
        </div>
        <div>
            <label for="phone" style="display:block; font-size: 0.85rem; font-weight: 600; margin-bottom: 0.4rem;">@lang('contact.phone')</label>
            <input type="text" id="phone" name="phone" value="{{ $old['phone'] ?? '' }}"
                   style="width: 100%; padding: 0.7rem 0.9rem; background: #0d1117; border: 1px solid var(--border-color); border-radius: 8px; color: var(--text-primary);">
        </div>
        <div>
            <label for="message" style="display:block; font-size: 0.85rem; font-weight: 600; margin-bottom: 0.4rem;">@lang('contact.message')</label>
            <textarea id="message" name="message" rows="5" required
                      style="width: 100%; padding: 0.7rem 0.9rem; background: #0d1117; border: 1px solid var(--border-color); border-radius: 8px; color: var(--text-primary);">{{ $old['message'] ?? '' }}</textarea>
        </div>
        <button type="submit" class="btn btn-primary" style="align-self: flex-start;">@lang('contact.submit')</button>
    </form>
</div>
@endsection
