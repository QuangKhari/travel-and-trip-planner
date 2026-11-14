{{-- Hoạt động thêm tùy chọn của tour: khách chọn ở bước đặt tour, giá do máy chủ tính --}}
@if (\Illuminate\Support\Facades\Schema::hasTable('tbl_tour_addons'))
    <section class="ad-card" style="margin-top:16px">
        <div class="ad-card__head"><h2>Hoạt động thêm tùy chọn</h2></div>
        @if ($addons->isNotEmpty())
            <table class="ad-table"><thead><tr><th>Tên</th><th>Giá</th><th>Tính theo</th><th>Trạng thái</th><th></th></tr></thead><tbody>
            @foreach ($addons as $a)
                <tr><td><b>{{ $a->name }}</b><br><small class="ad-muted">{{ $a->description }}</small></td><td>{{ number_format($a->price, 0, ',', '.') }}đ</td>
                    <td>{{ $a->unit === 'group' ? 'nhóm' : 'người' }}</td><td>{{ $a->isActive ? 'đang bán' : 'đã tắt' }}</td>
                    <td><form method="POST" action="{{ route('admin.tour-addons.toggle', [$tour->tourId, $a->addonId]) }}">@csrf<button class="ad-btn ad-btn--sm ad-btn--ghost">{{ $a->isActive ? 'Tắt' : 'Bật' }}</button></form></td></tr>
            @endforeach
            </tbody></table>
        @endif
        <form method="POST" action="{{ route('admin.tour-addons.store', $tour->tourId) }}" class="ad-form" style="padding:12px 16px;display:grid;grid-template-columns:2fr 2fr 1fr 1fr auto;gap:8px;align-items:end">@csrf
            <div class="ad-fld"><label>Tên</label><input name="name" required maxlength="160" placeholder="Ví dụ: Chèo kayak vịnh Lan Hạ"></div>
            <div class="ad-fld"><label>Mô tả</label><input name="description" maxlength="400"></div>
            <div class="ad-fld"><label>Giá (đ)</label><input type="number" name="price" min="0" required></div>
            <div class="ad-fld"><label>Tính theo</label><select name="unit"><option value="person">người</option><option value="group">nhóm</option></select></div>
            <button class="ad-btn ad-btn--primary" style="width:auto">Thêm</button>
        </form>
    </section>
@endif
