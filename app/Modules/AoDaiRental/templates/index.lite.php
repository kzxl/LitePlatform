@extends('layouts.app')

@section('content')
<style>
    .ad-hero { display: flex; justify-content: space-between; align-items: flex-end; gap: 1rem; flex-wrap: wrap; margin-bottom: 1.5rem; }
    .ad-hero h1 { font-size: 1.85rem; font-weight: 800; letter-spacing: -0.02em; }
    .ad-hero p { color: var(--text-secondary); font-size: 0.95rem; margin-top: 0.25rem; }
    .ad-filters { display: grid; grid-template-columns: 2fr 1fr 1fr 1fr auto; gap: 0.75rem; margin-bottom: 1.75rem; align-items: center; }
    @media (max-width: 860px) { .ad-filters { grid-template-columns: 1fr 1fr; } }
    .ad-input { width: 100%; padding: 0.58rem 0.78rem; border: 1px solid var(--border-color); border-radius: 7px; background: var(--surface-color); color: var(--text-primary); font-size: 0.9rem; }
    .ad-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(245px, 1fr)); gap: 1.25rem; }
    .ad-card { background: var(--surface-color); border: 1px solid var(--border-color); border-radius: 10px; overflow: hidden; display: flex; flex-direction: column; transition: border-color 0.15s ease; }
    .ad-card:hover { border-color: var(--accent); }
    .ad-thumb { position: relative; aspect-ratio: 3 / 4; background: var(--surface-hover); overflow: hidden; display: flex; align-items: center; justify-content: center; color: var(--text-secondary); font-size: 0.85rem; }
    .ad-thumb img { width: 100%; height: 100%; object-fit: cover; display: block; }
    .ad-code { position: absolute; top: 10px; left: 10px; background: rgba(15, 23, 42, 0.82); color: #ffffff; padding: 0.2rem 0.55rem; border-radius: 5px; font-size: 0.74rem; font-weight: 700; letter-spacing: 0.03em; }
    .ad-body { padding: 1rem; display: flex; flex-direction: column; flex: 1; gap: 0.45rem; }
    .ad-title { font-size: 0.98rem; font-weight: 700; color: var(--text-primary); text-decoration: none; line-height: 1.35; }
    .ad-title:hover { color: var(--accent); }
    .ad-meta { font-size: 0.8rem; color: var(--text-secondary); }
    .ad-price { font-size: 1.05rem; font-weight: 800; color: var(--accent); margin-top: auto; padding-top: 0.35rem; }
    .ad-actions { display: flex; gap: 0.5rem; margin-top: 0.65rem; }
    .ad-actions .btn { flex: 1; padding: 0.48rem 0.65rem; font-size: 0.82rem; }
    .btn-picked { background: var(--success); border-color: var(--success); color: #ffffff; }
    .btn-picked:hover { background: #166534; }
    .ad-tray { position: fixed; bottom: 18px; left: 50%; transform: translateX(-50%); background: #0f172a; color: #f8fafc; padding: 0.8rem 1.25rem; border-radius: 10px; box-shadow: 0 8px 24px rgba(15, 23, 42, 0.22); display: none; align-items: center; gap: 1rem; z-index: 200; max-width: 94vw; flex-wrap: wrap; }
    .ad-tray.open { display: flex; }
    .ad-modal-bg { position: fixed; inset: 0; background: rgba(15, 23, 42, 0.55); z-index: 300; display: none; align-items: center; justify-content: center; padding: 1rem; }
    .ad-modal-bg.open { display: flex; }
    .ad-modal { background: var(--surface-color); border: 1px solid var(--border-color); border-radius: 10px; max-width: 520px; width: 100%; padding: 1.5rem; }
    .ad-alert { padding: 0.85rem 1rem; border-radius: 8px; margin-bottom: 1.25rem; font-size: 0.9rem; }
    .ad-alert-ok { background: #dcfce7; border: 1px solid #86efac; color: #166534; }
    .ad-alert-err { background: #fee2e2; border: 1px solid #fca5a5; color: #991b1b; }
</style>

<div class="ad-hero">
    <div>
        <h1>Bộ Sưu Tập Áo Dài Cho Thuê</h1>
        <p>Chọn nhiều mẫu yêu thích vào danh sách và gửi liên hệ thử đồ nhanh — {{ $total }} mẫu đang sẵn sàng.</p>
    </div>
    <button type="button" class="btn btn-primary" id="ad-open-modal-top">Liên hệ tư vấn nhanh</button>
</div>

@if($inquiryOk ?? null)
<div class="ad-alert ad-alert-ok">{{ $inquiryOk }}</div>
@endif
@if($inquiryErr ?? null)
<div class="ad-alert ad-alert-err">{{ $inquiryErr }}</div>
@endif

<form method="GET" action="/mau-ao-dai" class="card ad-filters">
    <input type="text" name="q" value="{{ $filters['q'] ?? '' }}" class="ad-input" placeholder="Tìm theo tên, mã mẫu (VD: AD-001) hoặc chất liệu...">
    <select name="category" class="ad-input">
        <option value="">Tất cả danh mục</option>
        @foreach($categories as $cat)
        <option value="{{ $cat['id'] }}" {{ ((int)($filters['category'] ?? 0) === (int)$cat['id']) ? 'selected' : '' }}>{{ $cat['name'] }}</option>
        @endforeach
    </select>
    <select name="color" class="ad-input">
        <option value="">Tất cả màu sắc</option>
        @foreach($colors as $col)
        <option value="{{ $col }}" {{ (($filters['color'] ?? '') === $col) ? 'selected' : '' }}>{{ $col }}</option>
        @endforeach
    </select>
    <input type="number" name="max_price" value="{{ $filters['max_price'] ?? '' }}" class="ad-input" placeholder="Giá thuê tối đa (đ)" step="50000" min="0">
    <button type="submit" class="btn btn-primary">Lọc mẫu</button>
</form>

@if(empty($items))
<div class="card" style="text-align:center;padding:3rem 1.5rem;">
    <h2 style="font-size:1.15rem;margin-bottom:0.5rem;">Chưa tìm thấy mẫu áo dài phù hợp</h2>
    <p style="color:var(--text-secondary);margin-bottom:1.25rem;">Thử xóa bộ lọc hoặc liên hệ tiệm để được gửi thêm mẫu mới về.</p>
    <a href="/mau-ao-dai" class="btn btn-outline">Xem tất cả mẫu</a>
</div>
@else
<div class="ad-grid">
    @foreach($items as $item)
    <article class="ad-card">
        <a href="/mau-ao-dai/{{ $item['slug'] }}" class="ad-thumb">
            <span class="ad-code">{{ $item['code'] }}</span>
            @if(!empty($item['featured_image']))
            <img src="{{ $item['featured_image'] }}" alt="{{ $item['name'] }}" loading="lazy">
            @else
            <span>Chưa có ảnh</span>
            @endif
        </a>
        <div class="ad-body">
            <a href="/mau-ao-dai/{{ $item['slug'] }}" class="ad-title">{{ $item['name'] }}</a>
            <div class="ad-meta">
                @if(!empty($item['color']))
                <span>Màu: {{ $item['color'] }}</span>
                @endif
                @if(!empty($item['sizes']))
                <span> &middot; Size: {{ $item['sizes'] }}</span>
                @endif
            </div>
            <div class="ad-price">{{ number_format((float)$item['rental_price'], 0, ',', '.') }} đ / lượt</div>
            <div class="ad-actions">
                <a href="/mau-ao-dai/{{ $item['slug'] }}" class="btn btn-outline">Chi tiết</a>
                <button type="button" class="btn btn-primary ad-pick-btn" data-code="{{ $item['code'] }}" data-name="{{ $item['name'] }}">+ Chọn mẫu</button>
            </div>
        </div>
    </article>
    @endforeach
</div>

@if($totalPages > 1)
<div style="display:flex;justify-content:center;gap:0.5rem;margin-top:2rem;">
    @for($p = 1; $p <= $totalPages; $p++)
    <a href="/mau-ao-dai?page={{ $p }}&q={{ urlencode($filters['q'] ?? '') }}&color={{ urlencode($filters['color'] ?? '') }}" class="btn {{ $p === $page ? 'btn-primary' : 'btn-outline' }}">{{ $p }}</a>
    @endfor
</div>
@endif
@endif

<div class="ad-tray" id="ad-tray" aria-live="polite">
    <div>
        <strong id="ad-tray-count">0</strong> mẫu đã chọn:
        <span id="ad-tray-codes" style="color:#cbd5e1;font-size:0.86rem;"></span>
    </div>
    <div style="display:flex;gap:0.5rem;">
        <button type="button" class="btn btn-primary" id="ad-open-modal">Đặt lịch thử / Liên hệ</button>
        @if(!empty($zaloPhone))
        <a href="https://zalo.me/{{ $zaloPhone }}" target="_blank" rel="noopener" class="btn btn-outline" id="ad-zalo-btn" style="background:#ffffff;color:#0f172a;">Chat Zalo</a>
        @endif
        <button type="button" class="btn btn-outline" id="ad-clear-tray" style="background:transparent;color:#cbd5e1;border-color:#475569;">Xóa</button>
    </div>
</div>

<div class="ad-modal-bg" id="ad-modal-bg">
    <div class="ad-modal">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:1rem;">
            <h2 style="font-size:1.15rem;font-weight:700;">Gửi yêu cầu giữ mẫu &amp; thử áo dài</h2>
            <button type="button" id="ad-close-modal" class="btn btn-outline" style="padding:0.25rem 0.6rem;">&times;</button>
        </div>
        <form method="POST" action="/mau-ao-dai/inquiry" style="display:flex;flex-direction:column;gap:0.85rem;">
            @csrf
            @honeypot
            <div>
                <label style="display:block;font-size:0.85rem;font-weight:600;margin-bottom:0.3rem;">Mã mẫu đã chọn</label>
                <input type="text" name="selected_codes" id="ad-selected-codes-input" value="{{ $old['selected_codes'] ?? '' }}" class="ad-input" placeholder="VD: AD-001, AD-005">
            </div>
            <div>
                <label style="display:block;font-size:0.85rem;font-weight:600;margin-bottom:0.3rem;">Họ và tên *</label>
                <input type="text" name="name" value="{{ $old['name'] ?? '' }}" class="ad-input" required placeholder="Nguyễn Thị Lan">
            </div>
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:0.75rem;">
                <div>
                    <label style="display:block;font-size:0.85rem;font-weight:600;margin-bottom:0.3rem;">Số điện thoại / Zalo *</label>
                    <input type="text" name="phone" value="{{ $old['phone'] ?? '' }}" class="ad-input" required placeholder="0909 123 456">
                </div>
                <div>
                    <label style="display:block;font-size:0.85rem;font-weight:600;margin-bottom:0.3rem;">Ngày dự kiến mặc</label>
                    <input type="date" name="wear_date" value="{{ $old['wear_date'] ?? '' }}" class="ad-input">
                </div>
            </div>
            <div>
                <label style="display:block;font-size:0.85rem;font-weight:600;margin-bottom:0.3rem;">Ghi chú (size, chiều cao, cân nặng...)</label>
                <textarea name="notes" rows="2" class="ad-input" placeholder="Chiều cao 1m60, 50kg, muốn thử vào cuối tuần">{{ $old['notes'] ?? '' }}</textarea>
            </div>
            <button type="submit" class="btn btn-primary" style="width:100%;">Gửi yêu cầu liên hệ nhanh</button>
        </form>
    </div>
</div>

<script>
(function () {
    const STORAGE_KEY = 'aodai_selected_codes';
    let picked = [];
    try { picked = JSON.parse(localStorage.getItem(STORAGE_KEY) || '[]'); if (!Array.isArray(picked)) picked = []; } catch (e) { picked = []; }

    const tray = document.getElementById('ad-tray');
    const trayCount = document.getElementById('ad-tray-count');
    const trayCodes = document.getElementById('ad-tray-codes');
    const modalBg = document.getElementById('ad-modal-bg');
    const codesInput = document.getElementById('ad-selected-codes-input');

    function save() { localStorage.setItem(STORAGE_KEY, JSON.stringify(picked)); }
    function syncUi() {
        document.querySelectorAll('.ad-pick-btn').forEach(btn => {
            const code = btn.getAttribute('data-code');
            const active = picked.includes(code);
            btn.classList.toggle('btn-picked', active);
            btn.classList.toggle('btn-primary', !active);
            btn.textContent = active ? '✓ Đã chọn' : '+ Chọn mẫu';
        });
        if (tray) {
            tray.classList.toggle('open', picked.length > 0);
            trayCount.textContent = String(picked.length);
            trayCodes.textContent = picked.join(', ');
        }
        if (codesInput && picked.length > 0) {
            codesInput.value = picked.join(', ');
        }
    }

    document.querySelectorAll('.ad-pick-btn').forEach(btn => {
        btn.addEventListener('click', () => {
            const code = btn.getAttribute('data-code');
            if (!code) return;
            const idx = picked.indexOf(code);
            if (idx >= 0) picked.splice(idx, 1); else picked.push(code);
            save();
            syncUi();
        });
    });

    document.getElementById('ad-clear-tray')?.addEventListener('click', () => { picked = []; save(); syncUi(); });
    document.getElementById('ad-open-modal')?.addEventListener('click', () => { syncUi(); modalBg?.classList.add('open'); });
    document.getElementById('ad-open-modal-top')?.addEventListener('click', () => { syncUi(); modalBg?.classList.add('open'); });
    document.getElementById('ad-close-modal')?.addEventListener('click', () => modalBg?.classList.remove('open'));
    modalBg?.addEventListener('click', e => { if (e.target === modalBg) modalBg.classList.remove('open'); });

    syncUi();
})();
</script>
@endsection
