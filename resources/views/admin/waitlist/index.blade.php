@extends('admin.layout')
@section('content')
    <div class="ad-head">
        <div>
            <h1>Danh sách chờ</h1>
            <p>Khách đăng ký chờ khi ngày khởi hành hết chỗ. Có chỗ trống (khách hủy, hết hạn giữ chỗ) hệ thống giữ chỗ cho người chờ sớm nhất vừa chỗ và gửi email mời; quá hạn thì mời người kế tiếp.</p>
        </div>
        @if ($ready)<form method="POST" action="{{ route('admin.waitlist.process') }}">@csrf<button class="ad-btn" type="submit"><i class="fa fa-refresh"></i> Xử lý ngay</button></form>@endif
    </div>
    @if (!$ready)
        <div class="ad-card"><div class="ad-empty"><i class="fa fa-database"></i><b>Chưa chạy migration</b>Chạy <code>php artisan migrate</code> (2026_11_30_000001).</div></div>
    @else
        @if ($deps->count())
            <section class="ad-card" style="margin-bottom:20px"><div class="ad-card__head"><h2>Lịch đang có người chờ</h2></div>
                <div class="ad-tablewrap"><table class="ad-table"><thead><tr><th>Tour</th><th>Ngày đi</th><th>Chỗ trống</th><th>Chờ</th><th>Đang mời</th><th></th></tr></thead><tbody>
                    @foreach ($deps as $d)
                        <tr><td>{{ $d->title }}</td><td class="nowrap">{{ date('d/m/Y', strtotime($d->departureDate)) }}</td>
                            <td>{{ max(0, $d->totalSlots - $d->bookedSlots - $d->heldSlots) }}</td><td>{{ (int) $d->waiting }}</td><td>{{ (int) $d->offered }}</td>
                            <td class="num"><form method="POST" action="{{ route('admin.waitlist.offer', $d->availabilityId) }}">@csrf<button class="ad-btn ad-btn--sm" type="submit">Mời ngay</button></form></td></tr>
                    @endforeach
                </tbody></table></div></section>
        @endif
        <div class="ad-card">
            <div class="ad-toolbar"><div class="ad-chips">
                @foreach (['active' => 'Đang chờ / mời', 'converted' => 'Đã đặt tour', 'expired' => 'Hết hiệu lực', 'cancelled' => 'Đã rút', 'all' => 'Tất cả'] as $k => $l)
                    <a class="ad-chip {{ $status === $k ? 'is-on' : '' }}" href="{{ route('admin.waitlist', ['status' => $k]) }}">{{ $l }}</a>
                @endforeach
            </div></div>
            @if ($rows->count())
                <div class="ad-tablewrap"><table class="ad-table"><thead><tr><th>Khách</th><th>Tour / ngày đi</th><th>Số khách</th><th>Trạng thái</th><th>Đăng ký</th><th class="num"></th></tr></thead><tbody>
                    @foreach ($rows as $r)
                        <tr><td><b>{{ $r->fullName }}</b><span class="ad-sub">{{ $r->email }} · {{ $r->phoneNumber }}</span></td>
                            <td>{{ $r->title }}<span class="ad-sub">{{ date('d/m/Y', strtotime($r->departureDate)) }}</span></td>
                            <td>{{ $r->numAdults }} NL{{ $r->numChildren ? ' + ' . $r->numChildren . ' TE' : '' }}</td>
                            <td><span class="ad-badge ad-badge--{{ ['waiting' => 'violet', 'offered' => 'warn', 'converted' => 'ok', 'expired' => 'mute', 'cancelled' => 'mute'][$r->status] ?? 'mute' }}">{{ ['waiting' => 'Đang chờ', 'offered' => 'Đã mời', 'converted' => 'Đã đặt', 'expired' => 'Hết hiệu lực', 'cancelled' => 'Đã rút'][$r->status] ?? $r->status }}</span>
                                @if ($r->status === 'offered')<span class="ad-sub">Hạn {{ date('H:i d/m', strtotime($r->offerExpiresAt)) }}</span>@endif</td>
                            <td class="nowrap">{{ date('d/m H:i', strtotime($r->createdAt)) }}</td>
                            <td class="num">@if (in_array($r->status, ['waiting', 'offered'], true))<form method="POST" action="{{ route('admin.waitlist.cancel', $r->waitId) }}">@csrf<button class="ad-btn ad-btn--sm" type="submit" data-confirm="Gỡ khách này khỏi danh sách chờ?">Gỡ</button></form>@endif</td></tr>
                    @endforeach
                </tbody></table></div>
                @if ($rows->hasPages())<div class="ad-pager">{{ $rows->links('admin.partials.pager') }}</div>@endif
            @else
                <div class="ad-empty"><i class="fa fa-check-circle"></i><b>Không có ai trong danh sách</b></div>
            @endif
        </div>
    @endif
@endsection
