@extends('layouts.app')

@section('content')
<style>
    .rt-detail { display: grid; grid-template-columns: minmax(0, 1.05fr) minmax(0, 1fr); gap: 2rem; align-items: start; }
    @media (max-width: 820px) { .rt-detail { grid-template-columns: 1fr; } }
    .rt-main-photo { aspect-ratio: 3 / 4; border-radius: 10px; overflow: hidden; border: 1px solid var(--border-color); background: var(--surface-hover); display: flex; align-items: center; justify-content: center; }
    .rt-main-photo img { width: 100%; height: 100%; object-fit: cover; display: block; }
    .rt-thumbs { display: grid; grid-template-columns: repeat(auto-fill, minmax(76px, 1fr)); gap: 0.6rem; margin-top: 0.75rem; }
    .rt-thumb-btn { border: 2px solid var(--border-color); border-radius: 7px; overflow: hidden; padding: 0; background: var(--surface-color); cursor: pointer; aspect-ratio: 3 / 4; }
    .rt-thumb-btn.active { border-color: var(--accent); }
    .rt-thumb-btn img { width: 100%; height: 100%; object-fit: cover; display: block; }
    .rt-specs { width: 100%; border-collapse: collapse; margin: 1rem 0; font-size: 0.92rem; }
    .rt-specs td { padding: 0.5rem 0; border-bottom: 1px solid var(--border-color); }
    .rt-specs td:first-child { color: var(--text-secondary); width: 40%; }
    .rt-specs td:last-child { font-weight: 600; }
    .rt-input { width: 100%; padding: 0.58rem 0.78rem; border: 1px solid var(--border-color); border-radius: 7px; background: var(--surface-color); color: var(--text-primary); font-size: 0.9rem; }
    .rt-alert { padding: 0.85rem 1rem; border-radius: 8px; margin-bottom: 1rem; font-size: 0.9rem; }
    .rt-alert-ok { background: #dcfce7; border: 1px solid #86efac; color: #166534; }
    .rt-alert-err { background: #fee2e2; border: 1px solid #fca5a5; color: #991b1b; }
</style>

<div style="margin-bottom:1.25rem;">
    <a href="/catalog" style="color:var(--text-secondary);text-decoration:none;font-size:0.9rem;">&larr; Quay lại danh mục</a>
</div>

<div class="rt-detail">
    <div>
        <div class="rt-main-photo">
            @if(!empty($gallery))
            <img id="rt-main-img" src="{{ $gallery[0] }}" alt="{{ $item['name'] }}">
            @else
            <span style="color:var(--text-secondary);">Chưa có ảnh sản phẩm</span>
            @endif
        </div>
        @if(count($gallery) > 1)
        <div class="rt-thumbs">
            @foreach($gallery as $idx => $imgUrl)
            <button type="button" class="rt-thumb-btn {{ $idx === 0 ? 'active' : '' }}" data-img="{{ $imgUrl }}">
                <img src="{{ $imgUrl }}" alt="{{ $item['name'] }}">
            </button>
            @endforeach
        </div>
        @endif
    </div>

    <div>
        <span style="display:inline-block;padding:0.2rem 0.6rem;border-radius:6px;background:var(--surface-hover);border:1px solid var(--border-color);font-size:0.78rem;font-weight:700;margin-bottom:0.5rem;">
            SKU: {{ $item['sku'] }}
        </span>
        <h1 style="font-size:1.65rem;font-weight:800;line-height:1.25;margin-bottom:0.5rem;">{{ $item['name'] }}</h1>
        <div style="font-size:1.45rem;font-weight:800;color:var(--accent);margin-bottom:0.25rem;">
            {{ number_format((float)$item['price'], 0, ',', '.') }} đ
        </div>
        @if((float)($item['deposit_default'] ?? 0) > 0)
        <p style="font-size:0.85rem;color:var(--text-secondary);margin-bottom:1rem;">
            Tiền cọc giữ đồ (hoàn lại khi trả): <strong>{{ number_format((float)$item['deposit_default'], 0, ',', '.') }} đ</strong>
        </p>
        @endif

        <table class="rt-specs">
            <tr><td>Hình thức</td><td>{{ ($item['listing_type'] ?? 'rent') === 'sale' ? 'Mua bán' : (($item['listing_type'] ?? 'rent') === 'both' ? 'Cho thuê & Mua bán' : 'Cho thuê') }}</td></tr>
            @if(!empty($item['variant_summary']))
            <tr><td>Phân loại</td><td>{{ $item['variant_summary'] }}</td></tr>
            @endif
            @foreach($attributes as $attr)
            <tr><td>{{ $attr['key'] }}</td><td>{{ $attr['value'] }}</td></tr>
            @endforeach
            <tr><td>Trạng thái</td><td>{{ ($item['status'] ?? '') === 'active' ? 'Sẵn sàng' : 'Đang bảo dưỡng' }}</td></tr>
        </table>

        @if(!empty($item['description']))
        <div style="margin:1.25rem 0;line-height:1.7;color:var(--text-primary);">
            @sanitize($item['description'])
        </div>
        @endif

        <div style="display:flex;gap:0.65rem;margin-bottom:1.5rem;flex-wrap:wrap;">
            <button type="button" class="btn btn-outline" id="rt-detail-pick" data-sku="{{ $item['sku'] }}">+ Lưu vào danh sách chọn</button>
            @if(!empty($zaloPhone))
            <a href="https://zalo.me/{{ $zaloPhone }}" target="_blank" rel="noopener" class="btn btn-primary">Nhắn Zalo mã {{ $item['sku'] }}</a>
            @endif
        </div>

        <div class="card">
            <h2 style="font-size:1.05rem;font-weight:700;margin-bottom:0.85rem;">Gửi yêu cầu đặt giữ mã {{ $item['sku'] }}</h2>
            @if($inquiryOk ?? null)
            <div class="rt-alert rt-alert-ok">{{ $inquiryOk }}</div>
            @endif
            @if($inquiryErr ?? null)
            <div class="rt-alert rt-alert-err">{{ $inquiryErr }}</div>
            @endif
            <form method="POST" action="/catalog/inquiry" style="display:flex;flex-direction:column;gap:0.75rem;">
                @csrf
                @honeypot
                <input type="hidden" name="return_slug" value="{{ $item['slug'] }}">
                <input type="hidden" name="selected_skus" value="{{ $item['sku'] }}">
                <div>
                    <label style="display:block;font-size:0.84rem;font-weight:600;margin-bottom:0.25rem;">Họ và tên *</label>
                    <input type="text" name="name" value="{{ $old['name'] ?? '' }}" class="rt-input" required placeholder="Nguyễn Văn A">
                </div>
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:0.65rem;">
                    <div>
                        <label style="display:block;font-size:0.84rem;font-weight:600;margin-bottom:0.25rem;">Số điện thoại / Zalo *</label>
                        <input type="text" name="phone" value="{{ $old['phone'] ?? '' }}" class="rt-input" required placeholder="0909 123 456">
                    </div>
                    <div>
                        <label style="display:block;font-size:0.84rem;font-weight:600;margin-bottom:0.25rem;">Ngày dự kiến</label>
                        <input type="date" name="target_date" value="{{ $old['target_date'] ?? '' }}" class="rt-input">
                    </div>
                </div>
                <div>
                    <label style="display:block;font-size:0.84rem;font-weight:600;margin-bottom:0.25rem;">Ghi chú</label>
                    <textarea name="notes" rows="2" class="rt-input" placeholder="Phân loại, kích thước hoặc yêu cầu thêm">{{ $old['notes'] ?? '' }}</textarea>
                </div>
                <button type="submit" class="btn btn-primary" style="width:100%;">Gửi yêu cầu ngay</button>
            </form>
        </div>
    </div>
</div>

<script>
(function () {
    const mainImg = document.getElementById('rt-main-img');
    document.querySelectorAll('.rt-thumb-btn').forEach(btn => {
        btn.addEventListener('click', () => {
            if (mainImg) mainImg.src = btn.getAttribute('data-img') || '';
            document.querySelectorAll('.rt-thumb-btn').forEach(b => b.classList.remove('active'));
            btn.classList.add('active');
        });
    });
    const pickBtn = document.getElementById('rt-detail-pick');
    if (pickBtn) {
        const key = 'retail_selected_skus';
        const sku = pickBtn.getAttribute('data-sku');
        function read() { try { const a = JSON.parse(localStorage.getItem(key) || '[]'); return Array.isArray(a) ? a : []; } catch (e) { return []; } }
        function paint() { pickBtn.textContent = read().includes(sku) ? '✓ Đã lưu vào danh sách chọn' : '+ Lưu vào danh sách chọn'; }
        pickBtn.addEventListener('click', () => {
            const list = read();
            const i = list.indexOf(sku);
            if (i >= 0) list.splice(i, 1); else list.push(sku);
            localStorage.setItem(key, JSON.stringify(list));
            paint();
        });
        paint();
    }
})();
</script>
@endsection
