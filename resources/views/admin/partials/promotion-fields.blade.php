{{-- Trường dùng chung cho Thêm / Sửa mã giảm giá. $p = dòng tbl_promotion hoặc null. --}}
@php $v = fn($k, $d = '') => old($k, $p->$k ?? $d); @endphp
<div class="ad-form ad-form--2">
    <div class="ad-fld"><label for="pf-code">Mã <span class="req">*</span></label>
        <input id="pf-code" name="code" required maxlength="50" value="{{ $v('code') }}" placeholder="VD: HE2026" style="text-transform:uppercase" pattern="[A-Za-z0-9_-]+">
        <span class="ad-hint">Chữ không dấu, số, gạch ngang. Tự viết hoa, không trùng.</span></div>
    <div class="ad-fld"><label for="pf-desc">Mô tả <span class="req">*</span></label>
        <input id="pf-desc" name="description" required maxlength="255" value="{{ $v('description') }}" placeholder="VD: Giảm hè cho tour miền Trung"></div>
    <div class="ad-fld"><label for="pf-discount">Giảm (%) <span class="req">*</span></label>
        <div class="ad-input-group"><input id="pf-discount" type="number" name="discount" min="1" max="100" step="0.5" required value="{{ $v('discount') }}"><span class="ad-addon">%</span></div></div>
    <div class="ad-fld"><label for="pf-max">Giảm tối đa</label>
        <div class="ad-input-group"><input id="pf-max" type="number" name="maxDiscount" min="0" step="1000" value="{{ $v('maxDiscount') }}" placeholder="Không giới hạn"><span class="ad-addon">đ</span></div></div>
    <div class="ad-fld"><label for="pf-min">Đơn tối thiểu</label>
        <div class="ad-input-group"><input id="pf-min" type="number" name="minOrder" min="0" step="1000" value="{{ $v('minOrder', 0) }}"><span class="ad-addon">đ</span></div></div>
    <div class="ad-fld"><label for="pf-qty">Tổng số lượt <span class="req">*</span></label>
        <input id="pf-qty" type="number" name="quantity" min="{{ $p ? 0 : 1 }}" required value="{{ $v('quantity', 100) }}">
        <span class="ad-hint">Lượt còn lại; trừ khi có đơn dùng, cộng lại khi đơn bị hủy.</span></div>
    <div class="ad-fld"><label for="pf-per">Lượt / khách</label>
        <input id="pf-per" type="number" name="perUserLimit" min="0" max="100" value="{{ $v('perUserLimit') }}" placeholder="Theo cấu hình chung ({{ (int) config('travela.coupon_max_uses_per_user', 1) ?: 'không giới hạn' }})">
        <span class="ad-hint">0 = không giới hạn.</span></div>
    @if ($p)
        <div class="ad-fld"><label for="pf-status">Trạng thái</label>
            <select id="pf-status" name="status"><option value="y" @selected($v('status') === 'y')>Đang bật</option><option value="n" @selected($v('status') === 'n')>Tắt</option></select></div>
    @else
        <div></div>
    @endif
    <div class="ad-fld"><label for="pf-start">Từ ngày <span class="req">*</span></label>
        <input id="pf-start" type="date" name="startDate" required value="{{ $v('startDate', now()->toDateString()) }}"></div>
    <div class="ad-fld"><label for="pf-end">Đến hết ngày <span class="req">*</span></label>
        <input id="pf-end" type="date" name="endDate" required value="{{ $v('endDate', now()->addMonth()->toDateString()) }}">
        <span class="ad-hint">Dùng được trọn ngày cuối.</span></div>
</div>
