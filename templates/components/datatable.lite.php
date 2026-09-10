{{-- 
  Reusable HTMX DataTable Component
  Usage: @include('components.datatable', ['endpoint' => '/api/users', 'columns' => ['ID', 'Name', 'Email', 'Role']])
--}}
<div class="card" style="padding: 0; overflow: hidden;" id="datatable-root">
    <div style="padding: 1rem 1.25rem; display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid var(--border-color); background: rgba(22, 27, 34, 0.4);">
        <input 
            type="search" 
            name="search" 
            placeholder="🔍 Live instant filter..." 
            hx-get="{{ $endpoint ?? '' }}" 
            hx-trigger="keyup changed delay:300ms, search" 
            hx-target="#table-body" 
            hx-indicator="#table-spinner"
            style="padding: 0.5rem 0.85rem; border-radius: 6px; border: 1px solid var(--border-color); background: var(--bg-color); color: var(--text-primary); font-size: 0.85rem; outline: none; width: 280px;"
        >
        <span id="table-spinner" class="htmx-indicator" style="font-size: 0.8rem; color: var(--accent);">⚡ Loading...</span>
    </div>

    <table style="width: 100%; border-collapse: collapse; text-align: left; font-size: 0.9rem;">
        <thead>
            <tr style="border-bottom: 1px solid var(--border-color); background: rgba(110, 118, 129, 0.05); color: var(--text-secondary); text-transform: uppercase; font-size: 0.75rem; letter-spacing: 0.05em;">
                @foreach(($columns ?? []) as $col)
                <th style="padding: 0.85rem 1.25rem; font-weight: 600;">{{ $col }}</th>
                @endforeach
            </tr>
        </thead>
        <tbody id="table-body">
            @yield('table-rows')
        </tbody>
    </table>
</div>
