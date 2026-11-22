@extends('layouts.app')

@section('content')
<style>
    .rt-hero { display: flex; justify-content: space-between; align-items: flex-end; gap: 1rem; flex-wrap: wrap; margin-bottom: 1.5rem; }
    .rt-hero h1 { font-size: 1.85rem; font-weight: 800; letter-spacing: -0.02em; }
    .rt-hero p { color: var(--text-secondary); font-size: 0.95rem; margin-top: 0.25rem; }
    .rt-filters { display: grid; grid-template-columns: 2fr 1fr 1fr 1fr auto; gap: 0.75rem; margin-bottom: 1.75rem; align-items: center; }
    @media (max-width: 860px) { .rt-filters { grid-template-columns: 1fr 1fr; } }
    .rt-input { width: 100%; padding: 0.58rem 0.78rem; border: 1px solid var(--border-color); border-radius: 7px; background: var(--surface-color); color: var(--text-primary); font-size: 0.9rem; }
    .rt-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(245px, 1fr)); gap: 1.25rem; }
    .rt-card { background: var(--surface-color); border: 1px solid var(--border-color); border-radius: 10px; overflow: hidden; display: flex; flex-direction: column; transition: border-color 0.15s ease; }
    .rt-card:hover { border-color: var(--accent); }
    .rt-thumb { position: relative; aspect-ratio: 3 / 4; background: var(--surface-hover); overflow: hidden; display: flex; align-items: center; justify-content: center; color: var(--text-secondary); font-size: 0.85rem; }
    .rt-thumb img { width: 100%; height: 100%; object-fit: cover; display: block; }
    .rt-sku { position: absolute; top: 10px; left: 10px; background: rgba(15, 23, 42, 0.82); color: #ffffff; padding: 0.2rem 0.55rem; border-radius: 5px; font-size: 0.74rem; font-weight: 700; }
    .rt-mode { position: absolute; top: 10px; right: 10px; background: #ffffff; color: #0f172a; border: 1px solid var(--border-color); padding: 0.16rem 0.5rem; border-radius: 5px; font-size: 0.7rem; font-weight: 700; text-transform: uppercase; }
    .rt-body { padding: 1rem; display: flex; flex-direction: column; flex: 1; gap: 0.45rem; }
    .rt-title { font-size: 0.98rem; font-weight: 700; color: var(--text-primary); text-decoration: none; line-height: 1.35; }
    .rt-title:hover { color: var(--accent); }
    .rt-meta { font-size: 0.8rem; color: var(--text-secondary); }
    .rt-price { font-size: 1.05rem; font-weight: 800; color: var(--accent); margin-top: auto; padding-top: 0.35rem; }
    .rt-actions { display: flex; gap: 0.5rem; margin-top: 0.65rem; }
    .rt-actions .btn { flex: 1; padding: 0.48rem 0.65rem; font-size: 0.82rem; }
    .btn-picked { background: var(--success); border-color: var(--success); color: #ffffff; }
    .rt-tray { position: fixed; bottom: 18px; left: 50%; transform: translateX(-50%); background: #0f172a; color: #f8fafc; padding: 0.8rem 1.25rem; border-radius: 10px; box-shadow: 0 8px 24px rgba(15, 23, 42, 0.22); display: none; align-items: center; gap: 1rem; z-index: 200; max-width: 94vw; flex-wrap: wrap; }
    .rt-tray.open { display: flex; }
    .rt-modal-bg { position: fixed; inset: 0; background: rgba(15, 23, 42, 0.55); z-index: 300; display: none; align-items: center; justify-content: center; padding: 1rem; }
    .rt-modal-bg.open { display: flex; }
    .rt-modal { background: var(--surface-color); border: 1px solid var(--border-color); border-radius: 10px; max-width: 520px; width: 100%; padding: 1.5rem; }
    .rt-alert { padding: 0.85rem 1rem; border-radius: 8px; margin-bottom: 1.25rem; font-size: 0.9rem; }
    .rt-alert-ok { background: #dcfce7; border: 1px solid #86efac; color: #166534; }
    .rt-alert-err { background: #fee2e2; border: 1px solid #fca5a5; color: #991b1b; }
</style>

<div class="rt-hero">
    <div>
        <h1>{{ $catalogTitle ?? 'Catalog' }}</h1>
        <p>Chọn nhiều mẫu yêu thích vào danh sách và gửi yêu cầu tư vấn / đặt giữ nhanh — {{ $total }} sản phẩm đang sẵn sàng.</p>
    </div>
    <button type="button" class="btn btn-primary" id="rt-open-modal-top">Liên hệ tư vấn nhanh</button>
</div>

@if($inquiryOk ?? null)
<div class="rt-alert rt-alert-ok">{{ $inquiryOk }}</div>
@endif
@if($inquiryErr ?? null)
<div class="rt-alert rt-alert-err">{{ $inquiryErr }}</div>
@endif

<form method="GET" action="/catalog" class="card rt-filters">
    <input type="text" name="q" value="{{ $filters['q'] ?? '' }}" class="rt-input" placeholder="Tìm theo tên, mã SKU hoặc phân loại...">
    <select name="category" class="rt-input">
        <option value="">Tất cả danh mục</option>
        @foreach($categories as $cat)
        <option value="{{ $cat['id'] }}" {{ ((int)($filters['category'] ?? 0) === (int)$cat['id']) ? 'selected' : '' }}>{{ $cat['name'] }}</option>
        @endforeach
    </select>
    <select name="type" class="rt-input">
        <option value="">Thuê &amp; Mua</option>
        <option value="rent" {{ (($filters['type'] ?? '') === 'rent') ? 'selected' : '' }}>Cho thuê</option>
        <option value="sale" {{ (($filters['type'] ?? '') === 'sale') ? 'selected' : '' }}>Mua bán</option>
    </select>
    <input type="number" name="max_price" value="{{ $filters['max_price'] ?? '' }}" class="rt-input" placeholder="Giá tối đa (đ)" step="50000" min="0">
    <button type="submit" class="btn btn-primary">Lọc</button>
</form>

@if(empty($items))
<div class="card" style="text-align:center;padding:3rem 1.5rem;">
    <h2 style="font-size:1.15rem;margin-bottom:0.5rem;">Chưa có sản phẩm phù hợp</h2>
    <p style="color:var(--text-secondary);margin-bottom:1.25rem;">Thử đổi bộ lọc hoặc liên hệ cửa hàng để được tư vấn thêm mẫu mới.</p>
    <a href="/catalog" class="btn btn-outline">Xem toàn bộ danh mục</a>
</div>
@else
<div class="rt-grid">
    @foreach($items as $item)
    <article class="rt-card">
        <a href="/catalog/{{ $item['slug'] }}" class="rt-thumb">
            <span class="rt-sku">{{ $item['sku'] }}</span>
            <span class="rt-mode">{{ ($item['listing_type'] ?? 'rent') === 'sale' ? 'Bán' : (($item['listing_type'] ?? 'rent') === 'both' ? 'Thuê / Bán' : 'Cho thuê') }}</span>
            @if(!empty($item['featured_image']))
            <img src="{{ $item['featured_image'] }}" alt="{{ $item['name'] }}" loading="lazy">
            @else
            <span>Chưa có ảnh</span>
            @endif
        </a>
        <div class="rt-body">
            <a href="/catalog/{{ $item['slug'] }}" class="rt-title">{{ $item['name'] }}</a>
            @if(!empty($item['variant_summary']))
            <div class="rt-meta">{{ $item['variant_summary'] }}</div>
            @endif
            <div class="rt-price">{{ number_format((float)$item['price'], 0, ',', '.') }} đ</div>
            <div class="rt-actions">
                <a href="/catalog/{{ $item['slug'] }}" class="btn btn-outline">Chi tiết</a>
                <button type="button" class="btn btn-primary rt-pick-btn" data-sku="{{ $item['sku'] }}">+ Chọn</button>
            </div>
        </div>
    </article>
    @endforeach
</div>

@if($totalPages > 1)
<div style="display:flex;justify-content:center;gap:0.5rem;margin-top:2rem;">
    @for($p = 1; $p <= $totalPages; $p++)
    <a href="/catalog?page={{ $p }}&q={{ urlencode($filters['q'] ?? '') }}&type={{ urlencode($filters['type'] ?? '') }}" class="btn {{ $p === $page ? 'btn-primary' : 'btn-outline' }}">{{ $p }}</a>
    @endfor
</div>
@endif
@endif

<div class="rt-tray" id="rt-tray" aria-live="polite">
    <div>
        <strong id="rt-tray-count">0</strong> mục đã chọn:
        <span id="rt-tray-skus" style="color:#cbd5e1;font-size:0.86rem;"></span>
    </div>
    <div style="display:flex;gap:0.5rem;">
        <button type="button" class="btn btn-primary" id="rt-open-modal">Gửi liên hệ nhanh</button>
        @if(!empty($zaloPhone))
        <a href="https://zalo.me/{{ $zaloPhone }}" target="_blank" rel="noopener" class="btn btn-outline" style="background:#ffffff;color:#0f172a;">Chat Zalo</a>
        @endif
        <button type="button" class="btn btn-outline" id="rt-clear-tray" style="background:transparent;color:#cbd5e1;border-color:#475569;">Xóa</button>
    </div>
</div>

<div class="rt-modal-bg" id="rt-modal-bg">
    <div class="rt-modal">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:1rem;">
            <h2 style="font-size:1.15rem;font-weight:700;">Gửi yêu cầu tư vấn / đặt giữ</h2>
            <button type="button" id="rt-close-modal" class="btn btn-outline" style="padding:0.25rem 0.6rem;">&times;</button>
        </div>
        <form method="POST" action="/catalog/inquiry" style="display:flex;flex-direction:column;gap:0.85rem;">
            @csrf
            @honeypot
            <div>
                <label style="display:block;font-size:0.85rem;font-weight:600;margin-bottom:0.3rem;">Mã sản phẩm đã chọn</label>
                <input type="text" name="selected_skus" id="rt-selected-skus-input" value="{{ $old['selected_skus'] ?? '' }}" class="rt-input" placeholder="VD: ITEM-001, ITEM-005">
            </div>
            <div>
                <label style="display:block;font-size:0.85rem;font-weight:600;margin-bottom:0.3rem;">Họ và tên *</label>
                <input type="text" name="name" value="{{ $old['name'] ?? '' }}" class="rt-input" required placeholder="Nguyễn Văn A">
            </div>
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:0.75rem;">
                <div>
                    <label style="display:block;font-size:0.85rem;font-weight:600;margin-bottom:0.3rem;">Số điện thoại / Zalo *</label>
                    <input type="text" name="phone" value="{{ $old['phone'] ?? '' }}" class="rt-input" required placeholder="0909 123 456">
                </div>
                <div>
                    <label style="display:block;font-size:0.85rem;font-weight:600;margin-bottom:0.3rem;">Ngày dự kiến</label>
                    <input type="date" name="target_date" value="{{ $old['target_date'] ?? '' }}" class="rt-input">
                </div>
            </div>
            <div>
                <label style="display:block;font-size:0.85rem;font-weight:600;margin-bottom:0.3rem;">Ghi chú (phân loại, số đo, số lượng...)</label>
                <textarea name="notes" rows="2" class="rt-input" placeholder="Ghi chú thêm yêu cầu của bạn">{{ $old['notes'] ?? '' }}</textarea>
            </div>
            <button type="submit" class="btn btn-primary" style="width:100%;">Gửi yêu cầu ngay</button>
        </form>
    </div>
</div>

<script>
(function () {
    const KEY = 'retail_selected_skus';
    let picked = [];
    try { picked = JSON.parse(localStorage.getItem(KEY) || '[]'); if (!Array.isArray(picked)) picked = []; } catch (e) { picked = []; }

    const tray = document.getElementById('rt-tray');
    const trayCount = document.getElementById('rt-tray-count');
    const traySkus = document.getElementById('rt-tray-skus');
    const modalBg = document.getElementById('rt-modal-bg');
    const skusInput = document.getElementById('rt-selected-skus-input');

    function save() { localStorage.setItem(KEY, JSON.stringify(picked)); }
    function syncUi() {
        document.querySelectorAll('.rt-pick-btn').forEach(btn => {
            const sku = btn.getAttribute('data-sku');
            const active = picked.includes(sku);
            btn.classList.toggle('btn-picked', active);
            btn.classList.toggle('btn-primary', !active);
            btn.textContent = active ? '✓ Đã chọn' : '+ Chọn';
        });
        if (tray) {
            tray.classList.toggle('open', picked.length > 0);
            trayCount.textContent = String(picked.length);
            traySkus.textContent = picked.join(', ');
        }
        if (skusInput && picked.length > 0) skusInput.value = picked.join(', ');
    }

    document.querySelectorAll('.rt-pick-btn').forEach(btn => {
        btn.addEventListener('click', () => {
            const sku = btn.getAttribute('data-sku');
            if (!sku) return;
            const idx = picked.indexOf(sku);
            if (idx >= 0) picked.splice(idx, 1); else picked.push(sku);
            save();
            syncUi();
        });
    });

    document.getElementById('rt-clear-tray')?.addEventListener('click', () => { picked = []; save(); syncUi(); });
    document.getElementById('rt-open-modal')?.addEventListener('click', () => { syncUi(); modalBg?.classList.add('open'); });
    document.getElementById('rt-open-modal-top')?.addEventListener('click', () => { syncUi(); modalBg?.classList.add('open'); });
    document.getElementById('rt-close-modal')?.addEventListener('click', () => modalBg?.classList.remove('open'));
    modalBg?.addEventListener('click', e => { if (e.target === modalBg) modalBg.classList.remove('open'); });
    syncUi();
})();
</script>
@endsection
