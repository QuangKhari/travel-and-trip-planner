@php
    $money = fn($v) => number_format((float) $v, 0, ',', '.');
    $state = ['live' => ['ok', 'Đang chạy'], 'scheduled' => ['info', 'Sắp chạy'], 'expired' => ['mute', 'Hết hạn'], 'empty' => ['warn', 'Hết lượt'], 'off' => ['bad', 'Đã tắt']];
@endphp
@foreach ($list_promotion as $promotion)
    @php [$c, $l] = $state[$promotion->state ?? 'live'] ?? ['mute', '']; @endphp
    <tr data-status="{{ $promotion->state ?? '' }}" class="{{ in_array($promotion->state ?? '', ['expired', 'off'], true) ? 'is-muted' : '' }}">
        <td class="nowrap"><code class="ad-code" style="font-size:13px">{{ $promotion->code }}</code>
            <button type="button" class="ad-btn ad-btn--ghost ad-btn--sm ad-btn--icon" data-copy="{{ $promotion->code }}" title="Sao chép mã" aria-label="Sao chép mã {{ $promotion->code }}"><i class="fa fa-copy"></i></button>
            <span class="ad-sub" style="max-width:240px;white-space:normal">{{ $promotion->description }}</span></td>
        <td class="nowrap"><b style="font-size:15px;color:var(--ad-ink)">−{{ rtrim(rtrim(number_format((float) $promotion->discount, 1, ',', ''), '0'), ',') }}%</b>
            <span class="ad-sub">{{ !empty($promotion->maxDiscount) ? 'tối đa ' . $money($promotion->maxDiscount) . ' đ' : 'không giới hạn số tiền' }}</span></td>
        <td class="nowrap">{{ !empty($promotion->minOrder) ? 'Đơn từ ' . $money($promotion->minOrder) . ' đ' : 'Mọi đơn' }}
            <span class="ad-sub">{{ isset($promotion->perUserLimit) && $promotion->perUserLimit !== null ? ($promotion->perUserLimit ? $promotion->perUserLimit . ' lượt / khách' : 'không giới hạn / khách') : 'lượt / khách theo cấu hình chung' }}</span></td>
        <td class="nowrap">{{ date('d/m/Y', strtotime($promotion->startDate)) }} → {{ date('d/m/Y', strtotime($promotion->endDate)) }}</td>
        <td class="num nowrap">{{ $promotion->used ?? 0 }} dùng<span class="ad-sub">còn {{ $promotion->quantity }}</span></td>
        <td><span class="ad-badge ad-badge--{{ $c }}">{{ $l }}</span>@if (($promotion->discountTotal ?? 0) > 0)<span class="ad-sub">đã giảm {{ $money($promotion->discountTotal) }} đ</span>@endif</td>
        <td>
            <div class="ad-rowact">
                <a class="ad-btn ad-btn--sm" href="{{ route('admin.promotion-edit', $promotion->promotionId) }}"><i class="fa fa-pencil"></i> Sửa</a>
                <form action="{{ route('admin.delete-promotion') }}" method="POST" class="ad-inline-form">@csrf
                    <input type="hidden" name="promotionId" value="{{ $promotion->promotionId }}">
                    <button class="ad-btn ad-btn--sm ad-btn--ghost ad-btn--icon" title="Xóa mã" aria-label="Xóa mã {{ $promotion->code }}"
                        data-confirm="{{ ($promotion->used ?? 0) > 0 ? 'Mã đã có đơn dùng nên sẽ được TẮT (giữ lịch sử). Tiếp tục?' : 'Xóa mã ' . $promotion->code . '?' }}"><i class="fa fa-trash-o" style="color:var(--ad-bad)"></i></button>
                </form>
            </div>
        </td>
    </tr>
@endforeach
