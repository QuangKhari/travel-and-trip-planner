@php $money = fn($v) => number_format((float) $v, 0, ',', '.'); $today = now()->toDateString(); @endphp
@foreach ($tours as $tour)
    @php
        $upcoming = (int) ($tour->upcomingDeps ?? 0);
        $bookable = $tour->availability == 1 && (int) $tour->quantity > 0 && $tour->startDate > $today;
    @endphp
    <tr data-status="{{ $tour->availability == 1 ? 'on' : 'off' }}">
        <td style="min-width:300px">
            <div class="ad-person" style="min-width:0">
                <img class="ad-thumb" src="{{ \App\Support\TourImage::url($tour->cover ?? null, 320) }}" alt="" loading="lazy">
                <span style="min-width:0"><b title="{{ $tour->title }}">{{ \Illuminate\Support\Str::limit($tour->title, 58) }}</b>
                    <small>#{{ $tour->tourId }} · {{ $tour->time }} · {{ ['b' => 'Miền Bắc', 't' => 'Miền Trung', 'n' => 'Miền Nam'][$tour->domain] ?? '' }}</small></span>
            </div>
        </td>
        <td style="min-width:150px">{{ \Illuminate\Support\Str::limit($tour->destination, 40) }}
            @if (empty($tour->destinationId))
                <span class="ad-sub"><span class="ad-badge ad-badge--warn ad-badge--plain" title="Chưa gắn điểm đến: không lọc được theo cảm hứng, không đếm trên bản đồ">chưa gắn điểm đến</span></span>
            @else
                <span class="ad-sub">→ {{ $tour->destName }}</span>
            @endif
        </td>
        <td class="num nowrap">{{ $money($tour->priceAdult) }}<span class="ad-sub">TE {{ $money($tour->priceChild) }}</span></td>
        <td class="nowrap">
            {{ date('d/m/Y', strtotime($tour->startDate)) }}
            <span class="ad-sub">{{ $tour->startDate > $today ? 'còn ' . (int) $tour->quantity . ' chỗ' : 'đã qua' }}{{ $upcoming > 1 ? ' · +' . ($upcoming - 1) . ' ngày khác' : '' }}</span>
        </td>
        <td class="num">{{ (int) ($tour->activeBookings ?? 0) }}</td>
        <td>
            <a href="{{ route('admin.toggle-tour') }}" data-tourId="{{ $tour->tourId }}" class="toggle-tour" title="Bấm để {{ $tour->availability == 1 ? 'ẩn' : 'hiện' }} tour"
                data-confirm="{{ $tour->availability == 1 ? 'Ẩn tour này khỏi trang khách? Đơn đã đặt vẫn giữ nguyên.' : 'Hiện tour này cho khách đặt?' }}">
                @if ($tour->availability == 1)
                    <span class="ad-badge {{ $bookable ? 'ad-badge--ok' : 'ad-badge--warn' }}">{{ $bookable ? 'Đang bán' : 'Hiện · hết chỗ / quá ngày' }}</span>
                @else
                    <span class="ad-badge ad-badge--mute">Đang ẩn</span>
                @endif
            </a>
        </td>
        <td>
            <div class="ad-rowact">
                <a class="ad-btn ad-btn--sm" href="{{ route('admin.departures', ['tour' => $tour->tourId]) }}" title="Lịch khởi hành"><i class="fa fa-calendar"></i> Lịch</a>
                <button type="button" class="ad-btn ad-btn--sm edit-tour" data-toggle="modal" data-target="#edit-tour-modal"
                    data-tourId="{{ $tour->tourId }}" data-urledit="{{ route('admin.tour-edit') }}"><i class="fa fa-pencil"></i> Sửa</button>
                <a class="ad-btn ad-btn--sm ad-btn--icon ad-btn--ghost" href="{{ route('tour-detail', ['id' => $tour->tourId]) }}" target="_blank" rel="noopener" title="Xem trang tour" aria-label="Xem trang tour"><i class="fa fa-external-link"></i></a>
                <a href="{{ route('admin.delete-tour') }}" data-tourId="{{ $tour->tourId }}" class="delete-tour ad-btn ad-btn--sm ad-btn--icon ad-btn--ghost" title="Xóa tour" aria-label="Xóa tour"
                    data-confirm="{{ ($tour->activeBookings ?? 0) > 0 ? 'Tour đã có đơn nên sẽ được ẩn (ngừng bán), không xóa hẳn. Tiếp tục?' : 'Xóa hẳn tour này cùng ảnh và lộ trình?' }}"><i class="fa fa-trash-o" style="color:var(--ad-bad)"></i></a>
            </div>
        </td>
    </tr>
@endforeach
