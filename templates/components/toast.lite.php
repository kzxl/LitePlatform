{{-- 
  Reusable Toast Notification Component
  Usage: @include('components.toast', ['message' => 'Saved successfully', 'type' => 'success'])
--}}
<div id="toast-container" style="position: fixed; bottom: 1.5rem; right: 1.5rem; z-index: 2000; display: flex; flex-direction: column; gap: 0.75rem;">
    @if(isset($message) && $message !== '')
    <div class="card" style="padding: 0.75rem 1.25rem; display: flex; align-items: center; gap: 0.75rem; border-left: 4px solid var(--success); box-shadow: 0 10px 25px rgba(0,0,0,0.3); animation: slideIn 0.3s ease;" id="toast-msg">
        <span style="font-size: 1.1rem;">⚡</span>
        <span style="font-size: 0.9rem; font-weight: 500;">{{ $message }}</span>
    </div>
    <script>
        setTimeout(() => {
            const el = document.getElementById('toast-msg');
            if (el) el.remove();
        }, 3500);
    </script>
    @endif
</div>
