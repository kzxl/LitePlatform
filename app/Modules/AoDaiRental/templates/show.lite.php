@extends('layouts.app')

@section('content')
<style>
    .ad-detail { display: grid; grid-template-columns: minmax(0, 1.05fr) minmax(0, 1fr); gap: 2rem; align-items: start; }
    @media (max-width: 820px) { .ad-detail { grid-template-columns: 1fr; } }
    .ad-main-photo { aspect-ratio: 3 / 4; border-radius: 10px; overflow: hidden; border: 1px solid var(--border-color); background: var(--surface-hover); display: flex; align-items: center; justify-content: center; }
    .ad-main-photo img { width: 100%; height: 100%; object-fit: cover; display: block; }
    .ad-thumbs { display: grid; grid-template-columns: repeat(auto-fill, minmax(76px, 1fr)); gap: 0.6rem; margin-top: 0.75rem; }
    .ad-thumb-btn { border: 2px solid var(--border-color); border-radius: 7px; overflow: hidden; padding: 0; background: var(--surface-color); cursor: pointer; aspect-ratio: 3 / 4; }
    .ad-thumb-btn.active { border-color: var(--accent); }
    .ad-thumb-btn img { width: 100%; height: 100%; object-fit: cover; display: block; }
    .ad-specs { width: 100%; border-collapse: collapse; margin: 1rem 0; font-size: 0.92rem; }
    .ad-specs td { padding: 0.5rem 0; border-bottom: 1px solid var(--border-color); }
    .ad-specs td:first-child { color: var(--text-secondary); width: 40%; }
    .ad-specs td:last-child { font-weight: 600; }
    .ad-input { width: 100%; padding: 0.58rem 0.78rem; border: 1px solid var(--border-color); border-radius: 7px; background: var(--surface-color); color: var(--text-primary); font-size: 0.9rem; }
    .ad-alert { padding: 0.85rem 1rem; border-radius: 8px; margin-bottom: 1rem; font-size: 0.9rem; }
    .ad-alert-ok { background: #dcfce7; border: 1px solid #86efac; color: #166534; }
    .ad-alert-err { background: #fee2e2; border: 1px solid #fca5a5; color: #991b1b; }
</style>

<div style="margin-bottom:1.25rem;">
    <a href="/mau-ao-dai" style="color:var(--text-secondary);text-decoration:none;font-size:0.9rem;">&larr; Quay lại danh sách Mẫu Áo Dài</a>
</div>

<div class="ad-detail">
    <div>
        <div class="ad-main-photo">
            @if(!empty($gallery))
            <img id="ad-main-img" src="{{ $gallery[0] }}" alt="{{ $item['name'] }}">
            @else
            <span style="color:var(--text-secondary);">Chưa có ảnh sản phẩm</span>
            @endif
        </div>
        @if(count($gallery) > 1)
        <div class="ad-thumbs">
            @foreach($gallery as $idx => $imgUrl)
            <button type="button" class="ad-thumb-btn {{ $idx === 0 ? 'active' : '' }}" data-img="{{ $imgUrl }}">
                <img src="{{ $imgUrl }}" alt="{{ $item['name'] }}">
            </button>
            @endforeach
        </div>
        @endif
    </div>

    <div>
        <span style="display:inline-block;padding:0.2rem 0.6rem;border-radius:6px;background:var(--surface-hover);border:1px solid var(--border-color);font-size:0.78rem;font-weight:700;margin-bottom:0.5rem;">
            Mã mẫu: {{ $item['code'] }}
        </span>
        <h1 style="font-size:1.65rem;font-weight:800;line-height:1.25;margin-bottom:0.5rem;">{{ $item['name'] }}</h1>
        <div style="font-size:1.45rem;font-weight:800;color:var(--accent);margin-bottom:0.25rem;">
            {{ number_format((float)$item['rental_price'], 0, ',', '.') }} đ / lượt thuê
        </div>
        @if((float)($item['deposit_default'] ?? 0) > 0)
        <p style="font-size:0.85rem;color:var(--text-secondary);margin-bottom:1rem;">
            Tiền cọc giữ đồ (hoàn lại khi trả áo): <strong>{{ number_format((float)$item['deposit_default'], 0, ',', '.') }} đ</strong>
        </p>
        @endif

        <table class="ad-specs">
            @if(!empty($item['color']))
            <tr><td>Màu sắc</td><td>{{ $item['color'] }}</td></tr>
            @endif
            @if(!empty($item['sizes']))
            <tr><td>Size hiện có</td><td>{{ $item['sizes'] }}</td></tr>
            @endif
            @if(!empty($item['material']))
            <tr><td>Chất liệu</td><td>{{ $item['material'] }}</td></tr>
            @endif
            <tr><td>Trạng thái</td><td>{{ ($item['status'] ?? '') === 'available' ? 'Sẵn sàng cho thuê' : 'Đang bảo dưỡng' }}</td></tr>
        </table>

        @if(!empty($item['description']))
        <div style="margin:1.25rem 0;line-height:1.7;color:var(--text-primary);">
            @sanitize($item['description'])
        </div>
        @endif

        <div style="display:flex;gap:0.65rem;margin-bottom:1.5rem;flex-wrap:wrap;">
            <button type="button" class="btn btn-outline" id="ad-detail-pick" data-code="{{ $item['code'] }}">+ Lưu vào danh sách chọn</button>
            @if(!empty($zaloPhone))
            <a href="https://zalo.me/{{ $zaloPhone }}" target="_blank" rel="noopener" class="btn btn-primary">Nhắn Zalo thuê mẫu {{ $item['code'] }}</a>
            @endif
        </div>

        <div class="card">
            <h2 style="font-size:1.05rem;font-weight:700;margin-bottom:0.85rem;">Đặt lịch thử &amp; giữ mẫu {{ $item['code'] }}</h2>
            @if($inquiryOk ?? null)
            <div class="ad-alert ad-alert-ok">{{ $inquiryOk }}</div>
            @endif
            @if($inquiryErr ?? null)
            <div class="ad-alert ad-alert-err">{{ $inquiryErr }}</div>
            @endif
            <form method="POST" action="/mau-ao-dai/inquiry" style="display:flex;flex-direction:column;gap:0.75rem;">
                @csrf
                @honeypot
                <input type="hidden" name="return_slug" value="{{ $item['slug'] }}">
                <input type="hidden" name="selected_codes" value="{{ $item['code'] }}">
                <div>
                    <label style="display:block;font-size:0.84rem;font-weight:600;margin-bottom:0.25rem;">Họ và tên *</label>
                    <input type="text" name="name" value="{{ $old['name'] ?? '' }}" class="ad-input" required placeholder="Nguyễn Thị Lan">
                </div>
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:0.65rem;">
                    <div>
                        <label style="display:block;font-size:0.84rem;font-weight:600;margin-bottom:0.25rem;">Số điện thoại / Zalo *</label>
                        <input type="text" name="phone" value="{{ $old['phone'] ?? '' }}" class="ad-input" required placeholder="0909 123 456">
                    </div>
                    <div>
                        <label style="display:block;font-size:0.84rem;font-weight:600;margin-bottom:0.25rem;">Ngày dự kiến mặc</label>
                        <input type="date" name="wear_date" value="{{ $old['wear_date'] ?? '' }}" class="ad-input">
                    </div>
                </div>
                <div>
                    <label style="display:block;font-size:0.84rem;font-weight:600;margin-bottom:0.25rem;">Ghi chú (size, số đo...)</label>
                    <textarea name="notes" rows="2" class="ad-input" placeholder="Size M, muốn đến thử chiều thứ 7">{{ $old['notes'] ?? '' }}</textarea>
                </div>
                <button type="submit" class="btn btn-primary" style="width:100%;">Gửi yêu cầu giữ mẫu</button>
            </form>
        </div>
    </div>
</div>

<script>
(function () {
    const mainImg = document.getElementById('ad-main-img');
    document.querySelectorAll('.ad-thumb-btn').forEach(btn => {
        btn.addEventListener('click', () => {
            if (mainImg) mainImg.src = btn.getAttribute('data-img') || '';
            document.querySelectorAll('.ad-thumb-btn').forEach(b => b.classList.remove('active'));
            btn.classList.add('active');
        });
    });

    const pickBtn = document.getElementById('ad-detail-pick');
    if (pickBtn) {
        const key = 'aodai_selected_codes';
        const code = pickBtn.getAttribute('data-code');
        function read() { try { const a = JSON.parse(localStorage.getItem(key) || '[]'); return Array.isArray(a) ? a : []; } catch (e) { return []; } }
        function paint() {
            const picked = read().includes(code);
            pickBtn.textContent = picked ? '✓ Đã lưu vào danh sách chọn' : '+ Lưu vào danh sách chọn';
        }
        pickBtn.addEventListener('click', () => {
            const list = read();
            const i = list.indexOf(code);
            if (i >= 0) list.splice(i, 1); else list.push(code);
            localStorage.setItem(key, JSON.stringify(list));
            paint();
        });
        paint();
    }
})();
</script>
@endsection
