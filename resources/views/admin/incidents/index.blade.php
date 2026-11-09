@extends('admin.layout')
@section('content')
    <div class="ad-head">
        <div>
            <h1>Sự cố tour</h1>
            <p>Ghi nhận và theo dõi sự cố khi tổ chức chuyến đi (xe, khách sạn, hướng dẫn viên, thời tiết, sức khỏe khách…). Sự cố làm thay đổi lịch trình đã cam kết được nối với phương án Departure Rescue để xin xác nhận khách và xử lý hoàn tiền.</p>
        </div>
    </div>
    @if (!$ready)
        <div class="ad-card"><div class="ad-empty"><i class="fa fa-database"></i><b>Chưa chạy migration</b>Chạy <code>php artisan migrate</code> (2026_11_29_000001) rồi quay lại.</div></div>
    @else
        <section class="ad-card" style="margin-bottom:20px">
            <div class="ad-card__head"><h2>Ghi nhận sự cố mới</h2></div>
            <form method="POST" action="{{ route('admin.incidents.store') }}" class="ad-form" style="padding:0 16px 16px">
                @csrf
                <div class="ad-fld"><label>Tiêu đề <span class="req">*</span></label><input name="title" required minlength="5" maxlength="200" value="{{ old('title') }}" placeholder="Vd: Xe 45 chỗ hỏng động cơ trên đường đi Sa Pa"></div>
                <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:12px">
                    <div class="ad-fld"><label>Loại <span class="req">*</span></label><select name="type" required>@foreach ($types as $k => $l)<option value="{{ $k }}" @selected(old('type') === $k)>{{ $l }}</option>@endforeach</select></div>
                    <div class="ad-fld"><label>Mức nghiêm trọng <span class="req">*</span></label><select name="severity" required>@foreach ($severities as $k => $l)<option value="{{ $k }}" @selected(old('severity', 'medium') === $k)>{{ $l }}</option>@endforeach</select></div>
                    <div class="ad-fld"><label>Lịch khởi hành bị ảnh hưởng</label><select name="availabilityId"><option value="">Chưa xác định</option>@foreach ($departures as $d)<option value="{{ $d->availabilityId }}" @selected((string) old('availabilityId', request('availabilityId')) === (string) $d->availabilityId)>#{{ $d->availabilityId }} · {{ $d->title }} · {{ $d->departureDate }}</option>@endforeach</select></div>
                </div>
 @if (!empty($suppliers) && $suppliers->count())<div class="ad-fld"><label>Nhà cung cấp liên quan (tính vào đánh giá nhà cung cấp)</label><select name="supplierId"><option value="">Không / chưa rõ</option>@foreach ($suppliers as $s)<option value="{{ $s->supplierId }}">{{ $s->name }}</option>@endforeach</select></div>@endif
                <div class="ad-fld"><label>Mô tả</label><textarea name="description" rows="3" maxlength="3000" placeholder="Chuyện gì xảy ra, ai bị ảnh hưởng, đã làm gì…">{{ old('description') }}</textarea></div>
                <label style="display:block;margin:6px 0"><input type="checkbox" name="confidential" value="1"> Bảo mật nội dung (sự cố sức khỏe / an toàn luôn tự bảo mật)</label>
                <button class="ad-btn ad-btn--primary" type="submit">Ghi nhận sự cố</button>
            </form>
        </section>

        <div class="ad-card">
            <div class="ad-toolbar"><div class="ad-chips">
                @foreach (['active' => 'Đang xử lý', 'resolved' => 'Đã xử lý', 'closed' => 'Đã đóng', 'all' => 'Tất cả'] as $k => $l)
                    <a class="ad-chip {{ $status === $k ? 'is-on' : '' }}" href="{{ route('admin.incidents', ['status' => $k]) }}">{{ $l }}</a>
                @endforeach
            </div></div>
            @if ($rows->count())
                <div class="ad-tablewrap"><table class="ad-table">
                    <thead><tr><th>Mức</th><th>Sự cố</th><th>Tour / ngày đi</th><th>Loại</th><th>Trạng thái</th><th>Báo lúc</th></tr></thead>
                    <tbody>
                    @foreach ($rows as $r)
                        <tr>
                            <td><span class="ad-badge ad-badge--{{ ['critical' => 'bad', 'high' => 'bad', 'medium' => 'warn', 'low' => 'mute'][$r->severity] ?? 'mute' }}">{{ $severities[$r->severity] ?? $r->severity }}</span></td>
                            <td><a href="{{ route('admin.incidents.show', $r->incidentId) }}"><b>#{{ $r->incidentId }} · {{ $r->visible ? $r->title : 'Sự cố bảo mật' }}</b></a>@if ($r->confidential)<span class="ad-sub"><i class="fa fa-lock"></i> Bảo mật</span>@endif</td>
                            <td>{{ $r->tourTitle ?? '—' }}@if ($r->departureDate)<span class="ad-sub">Ngày đi {{ date('d/m/Y', strtotime($r->departureDate)) }}</span>@endif</td>
                            <td>{{ $types[$r->type] ?? $r->type }}</td>
                            <td><span class="ad-badge ad-badge--{{ ['open' => 'violet', 'handling' => 'warn', 'resolved' => 'mute', 'closed' => 'mute'][$r->status] ?? 'mute' }}">{{ $statuses[$r->status] ?? $r->status }}</span></td>
                            <td class="nowrap">{{ date('d/m H:i', strtotime($r->createdAt)) }}</td>
                        </tr>
                    @endforeach
                    </tbody></table></div>
                @if ($rows->hasPages())<div class="ad-pager">{{ $rows->links('admin.partials.pager') }}</div>@endif
            @else
                <div class="ad-empty"><i class="fa fa-check-circle"></i><b>Không có sự cố nào</b></div>
            @endif
        </div>
    @endif
@endsection
