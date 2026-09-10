{{-- 
  Reusable HTMX Modal Popup Component
  Usage: @include('components.modal', ['id' => 'user-modal', 'title' => 'User Details'])
--}}
<div id="{{ $id ?? 'modal' }}" style="display: none; position: fixed; inset: 0; background: rgba(0, 0, 0, 0.7); backdrop-filter: blur(4px); z-index: 1000; align-items: center; justify-content: center;">
    <div class="card" style="width: 100%; max-width: 500px; margin: 1.5rem; position: relative; box-shadow: 0 20px 40px rgba(0,0,0,0.5);">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.25rem;">
            <h3 style="font-size: 1.2rem; font-weight: 700;">{{ $title ?? 'Dialog' }}</h3>
            <button onclick="document.getElementById('{{ $id ?? 'modal' }}').style.display='none'" style="background: transparent; border: none; color: var(--text-secondary); font-size: 1.25rem; cursor: pointer;">✕</button>
        </div>
        <div id="{{ $id ?? 'modal' }}-body">
            @yield('modal-content')
        </div>
    </div>
</div>
