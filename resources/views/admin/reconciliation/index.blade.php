@extends('admin.layout')
@section('content')
    <div class="ad-head">
        <div>
            <h1>Đối soát thanh toán</h1>
            <p>Hệ thống quét mỗi giờ các điểm đơn đặt, tiền thu và hoàn tiền không khớp nhau. Phát hiện tự đóng khi hết đúng; muốn giữ nguyên trạng phải bỏ qua kèm lý do. Trang này chỉ báo, không tự sửa dữ liệu.</p>
        </div>
        @if ($ready)
            <form method="POST" action="{{ route('admin.reconciliation.run') }}">@csrf<button class="ad-btn ad-btn--primary" type="submit"><i class="fa fa-refresh"></i> Quét ngay</button></form>
        @endif
    </div>

    @if (!$ready)
        <div class="ad-card"><div class="ad-empty"><i class="fa fa-database"></i><b>Chưa chạy migration</b>Chạy <code>php artisan migrate</code> (2026_11_29_000001) rồi quay lại.</div></div>
    @else
        <div class="ad-kpis">
            <div class="ad-kpi"><div><div class="ad-kpi__l">Nghiêm trọng</div><div class="ad-kpi__v">{{ (int) ($counts['high'] ?? 0) }}</div><div class="ad-kpi__h">Có thể mất tiền / hoàn trùng</div></div></div>
            <div class="ad-kpi"><div><div class="ad-kpi__l">Cần kiểm tra</div><div class="ad-kpi__v">{{ (int) ($counts['medium'] ?? 0) }}</div><div class="ad-kpi__h">Không khớp số liệu</div></div></div>
            <div class="ad-kpi"><div><div class="ad-kpi__l">Nhắc việc</div><div class="ad-kpi__v">{{ (int) ($counts['low'] ?? 0) }}</div><div class="ad-kpi__h">Sắp đến hạn</div></div></div>
        </div>
        <div class="ad-card">
            <div class="ad-toolbar"><div class="ad-chips">
                @foreach (['open' => 'Đang mở', 'ignored' => 'Đã bỏ qua', 'resolved' => 'Tự đóng', 'all' => 'Tất cả'] as $k => $l)
                    <a class="ad-chip {{ $status === $k ? 'is-on' : '' }}" href="{{ route('admin.reconciliation', ['status' => $k, 'severity' => $severity]) }}">{{ $l }}</a>
                @endforeach
                @foreach (['high' => 'Nghiêm trọng', 'medium' => 'Cần kiểm tra', 'low' => 'Nhắc việc'] as $k => $l)
                    <a class="ad-chip {{ $severity === $k ? 'is-on' : '' }}" href="{{ route('admin.reconciliation', ['status' => $status, 'severity' => $severity === $k ? null : $k]) }}">{{ $l }}</a>
                @endforeach
            </div></div>
            @if ($rows->count())
                <div class="ad-tablewrap"><table class="ad-table">
                    <thead><tr><th>Mức</th><th>Vấn đề</th><th>Đơn</th><th>Chi tiết</th><th>Lần đầu / gần nhất</th><th class="num">Xử lý</th></tr></thead>
                    <tbody>
                    @foreach ($rows as $f)
                        @php $t = $types[$f->type] ?? [$f->type, $f->severity, '']; @endphp
                        <tr>
                            <td><span class="ad-badge ad-badge--{{ ['high' => 'bad', 'medium' => 'warn', 'low' => 'mute'][$f->severity] ?? 'mute' }}">{{ ['high' => 'Nghiêm trọng', 'medium' => 'Kiểm tra', 'low' => 'Nhắc'][$f->severity] ?? $f->severity }}</span></td>
                            <td style="max-width:280px"><b>{{ $t[0] }}</b>@if ($t[2])<span class="ad-sub">{{ $t[2] }}</span>@endif</td>
                            <td class="nowrap">@if ($f->bookingId)<a href="{{ route('admin.booking-detail', ['id' => $f->bookingId]) }}"><b>{{ $f->bookingCode ?? '#' . $f->bookingId }}</b></a>@endif
                                @if ($f->refundId)<span class="ad-sub"><a href="{{ route('admin.refunds', ['status' => 'all']) }}#refund-{{ $f->refundId }}">Hồ sơ hoàn #{{ $f->refundId }}</a></span>@endif</td>
                            <td style="max-width:300px">{{ $f->detail }}@if ($f->note)<span class="ad-sub">Ghi chú: {{ $f->note }}</span>@endif</td>
                            <td class="nowrap"><span class="ad-sub">{{ date('d/m H:i', strtotime($f->firstSeenAt)) }}</span><span class="ad-sub">{{ $f->lastSeenAt ? date('d/m H:i', strtotime($f->lastSeenAt)) : '' }}</span></td>
                            <td class="num">
                                @if ($f->status === 'open' || $f->status === 'resolved')
                                    <form method="POST" action="{{ route('admin.reconciliation.ignore', $f->findingId) }}" style="display:flex;gap:6px;justify-content:flex-end">@csrf
                                        <input name="note" required minlength="5" maxlength="255" placeholder="Lý do bỏ qua" style="width:150px"><button class="ad-btn ad-btn--sm" type="submit">Bỏ qua</button></form>
                                @elseif ($f->status === 'ignored')
                                    <form method="POST" action="{{ route('admin.reconciliation.reopen', $f->findingId) }}">@csrf<button class="ad-btn ad-btn--sm" type="submit">Mở lại</button></form>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                    </tbody></table></div>
                @if ($rows->hasPages())<div class="ad-pager">{{ $rows->links('admin.partials.pager') }}</div>@endif
            @else
                <div class="ad-empty"><i class="fa fa-check-circle"></i><b>Không có điểm nào không khớp</b>Bấm “Quét ngay” để kiểm tra lại.</div>
            @endif
        </div>
    @endif
@endsection
