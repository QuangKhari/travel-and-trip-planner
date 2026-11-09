@extends('admin.layout')
@section('content')
    <div class="ad-head">
        <div>
            <h1>Điều chỉnh số khách</h1>
            <p>Đơn đã thanh toán đổi số khách phát sinh khoản <b>thu thêm</b> (tăng người) hoặc <b>hoàn phần dư</b> theo chính sách hủy (giảm người). Chuyển / nhận tiền rồi ghi nhận ở đây; sổ này tách khỏi “Hoàn tiền” nên một đơn có thể điều chỉnh nhiều lần.</p>
        </div>
    </div>
    @if (!$ready)
        <div class="ad-card"><div class="ad-empty"><i class="fa fa-database"></i><b>Chưa chạy migration</b>Chạy <code>php artisan migrate</code> (2026_11_30_000001).</div></div>
    @else
        <div class="ad-card">
            <div class="ad-toolbar"><div class="ad-chips">
                @foreach (['pending' => 'Chờ thu / hoàn', 'done' => 'Đã xử lý', 'all' => 'Tất cả'] as $k => $l)
                    <a class="ad-chip {{ $status === $k ? 'is-on' : '' }}" href="{{ route('admin.adjustments', ['status' => $k]) }}">{{ $l }}</a>
                @endforeach
            </div></div>
            @if ($rows->count())
                <div class="ad-tablewrap"><table class="ad-table"><thead><tr><th>Đơn</th><th>Thay đổi</th><th>Tổng tiền</th><th>Khoản chờ</th><th>Bởi</th><th class="num">Xử lý</th></tr></thead><tbody>
                    @foreach ($rows as $r)
                        <tr><td><a href="{{ route('admin.booking-detail', ['id' => $r->bookingId]) }}"><b>{{ $r->bookingCode }}</b></a><span class="ad-sub">{{ $r->title }}</span></td>
                            <td>{{ $r->oldAdults }}+{{ $r->oldChildren }} → <b>{{ $r->newAdults }}+{{ $r->newChildren }}</b><span class="ad-sub">{{ date('d/m H:i', strtotime($r->createdAt)) }}</span></td>
                            <td>{{ number_format($r->oldTotal, 0, ',', '.') }} → <b>{{ number_format($r->newTotal, 0, ',', '.') }}</b></td>
                            <td>@if ($r->moneyStatus === 'pending_collect')<span class="ad-badge ad-badge--warn">Thu thêm {{ number_format($r->settleAmount, 0, ',', '.') }} đ</span>
                                @elseif ($r->moneyStatus === 'pending_refund')<span class="ad-badge ad-badge--violet">Hoàn {{ number_format($r->settleAmount, 0, ',', '.') }} đ ({{ $r->refundPercent }}%)</span>
                                @elseif ($r->moneyStatus === 'collected')<span class="ad-badge ad-badge--ok">Đã thu {{ $r->method }}</span>
                                @elseif ($r->moneyStatus === 'refunded')<span class="ad-badge ad-badge--ok">Đã hoàn {{ $r->method }}</span>
                                @else<span class="ad-badge ad-badge--mute">Không phát sinh</span>@endif</td>
                            <td>{{ $r->by === 'admin' ? 'Admin' : 'Khách' }}</td>
                            <td class="num">@if (in_array($r->moneyStatus, ['pending_collect', 'pending_refund'], true))
                                <form method="POST" action="{{ route('admin.adjustments.settle', $r->adjustId) }}" style="display:flex;gap:6px;justify-content:flex-end;flex-wrap:wrap">@csrf
                                    <select name="method"><option>Tiền mặt</option><option>Chuyển khoản</option><option>Ví điện tử</option></select>
                                    <input name="reference" maxlength="80" placeholder="Mã giao dịch" style="width:130px">
                                    <button class="ad-btn ad-btn--sm ad-btn--primary" type="submit" data-confirm="Xác nhận đã {{ $r->moneyStatus === 'pending_collect' ? 'thu' : 'hoàn' }} {{ number_format($r->settleAmount, 0, ',', '.') }} đ?">Đã {{ $r->moneyStatus === 'pending_collect' ? 'thu' : 'hoàn' }}</button></form>@endif</td></tr>
                    @endforeach
                </tbody></table></div>
                @if ($rows->hasPages())<div class="ad-pager">{{ $rows->links('admin.partials.pager') }}</div>@endif
            @else
                <div class="ad-empty"><i class="fa fa-check-circle"></i><b>Không có khoản nào</b></div>
            @endif
        </div>
    @endif
@endsection
