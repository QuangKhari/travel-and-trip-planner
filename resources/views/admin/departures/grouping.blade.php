@extends('admin.layout')
@section('content')
<div class="ad-head">
    <div><a class="ad-back" href="{{ route('admin.departures') }}">← Lịch khởi hành</a>
        <h1>Ghép đoàn khởi hành</h1><p><a href="{{ route('admin.departures.rescue') }}">Xử lý thiếu khách, ghép một phần, xin đổi ngày và xác nhận khách →</a></p>
        <p>Chỉ ghép toàn bộ hai tour khác nhau, cùng ngày đi, thời lượng, điểm đến và lịch trình giống nhau. Booking, giá đã bán và chỗ của mỗi tour vẫn độc lập. Số chỗ đoàn là tổng số chỗ của hai tour. Khách của cả hai lịch được email thông báo khi ghép hoặc tách đoàn.</p>
    </div>
</div>
@if (!$ready)
    <div class="ad-card"><div class="ad-card__body">Chức năng chưa sẵn sàng. Hãy chạy <code>php artisan migrate</code>.</div></div>
@else
    <section class="ad-card" style="margin-bottom:20px">
        <div class="ad-card__head"><h2>Lịch khởi hành sắp tới</h2></div>
        <div class="ad-tablewrap"><table class="ad-table">
            <thead><tr><th>Tour</th><th>Ngày đi</th><th>Đã xác nhận</th><th>Đang giữ</th><th>Tối thiểu</th><th>Đoàn / đề xuất</th></tr></thead>
            <tbody>
            @forelse ($departures as $a)
                <tr>
                    <td>{{ $a->title }}</td><td class="nowrap">{{ \Carbon\Carbon::parse($a->departureDate)->format('d/m/Y') }}</td>
                    <td>{{ $a->bookedSlots }}/{{ $a->totalSlots }} @if($a->bookedSlots < $a->minParticipants)<span class="ad-badge ad-badge--warn">Thiếu {{ $a->minParticipants - $a->bookedSlots }}</span>@endif</td>
                    <td>{{ $a->heldSlots }}</td>
                    <td><form method="POST" action="{{ route('admin.departures.minimum', $a->availabilityId) }}" style="display:flex;gap:5px;align-items:center">@csrf
                        <input class="ad-field" style="width:70px" type="number" name="minParticipants" min="1" max="{{ $a->totalSlots }}" value="{{ $a->minParticipants }}" required>
                        <button class="ad-btn ad-btn--sm" type="submit">Lưu</button>
                    </form></td>
                    <td>@if ($a->groupId)<b>Đoàn #{{ $a->groupId }}</b>
                        @else <a class="ad-btn ad-btn--sm" href="{{ route('admin.departures.grouping', ['availabilityId' => $a->availabilityId]) }}">Tìm tour ghép</a>@endif</td>
                </tr>
            @empty <tr><td colspan="6">Chưa có lịch khởi hành sắp tới.</td></tr>
            @endforelse
            </tbody></table></div>
    </section>
    @if ($selected)
    <section class="ad-card" style="margin-bottom:20px">
        <div class="ad-card__head"><h2>Tour tương thích với lịch #{{ $selected }}</h2></div>
        <div class="ad-card__body">
            <p>Hệ thống chỉ đề xuất tour có dữ liệu lịch trình trùng khớp. Admin phải xác minh thêm xe, khách sạn, hướng dẫn viên và cam kết dịch vụ trước khi ghép.</p>
            <p>So khớp:
                <a href="{{ route('admin.departures.grouping', ['availabilityId' => $selected, 'mode' => 'strict']) }}" @if($mode==='strict') style="font-weight:700" @endif>Giống hệt (tiêu đề + mô tả)</a> ·
                <a href="{{ route('admin.departures.grouping', ['availabilityId' => $selected, 'mode' => 'titles']) }}" @if($mode==='titles') style="font-weight:700" @endif>Chỉ giống tiêu đề từng ngày</a></p>
            @if($mode==='titles')<p class="ad-badge ad-badge--warn">Chế độ nới lỏng: mô tả từng ngày có thể khác nhau. Bạn phải tự đối chiếu nội dung và tích xác nhận trước khi ghép.</p>@endif
            @forelse ($candidates as $c)
            <form method="POST" action="{{ route('admin.departures.grouping.merge') }}" style="margin:12px 0;padding:12px;border:1px solid #ddd;border-radius:8px">
                @csrf<input type="hidden" name="firstId" value="{{ $selected }}"><input type="hidden" name="secondId" value="{{ $c->availabilityId }}"><input type="hidden" name="mode" value="{{ $mode }}">
                <p><b>{{ $c->title }}</b> · {{ \Carbon\Carbon::parse($c->departureDate)->format('d/m/Y') }} · {{ $c->bookedSlots }} đã bán, {{ $c->heldSlots }} đang giữ</p>
                @if($mode==='titles')<label style="display:block;margin:8px 0"><input type="checkbox" name="confirmTitles" value="1" required> Tôi đã đối chiếu nội dung từng ngày của hai tour và chấp nhận chạy chung</label>@endif
                <div class="ad-fld"><label>Ghi chú điều phối (tùy chọn)</label><input name="note" maxlength="1000" placeholder="Đã xác minh phương tiện, phòng và dịch vụ"></div>
                <button class="ad-btn ad-btn--primary" type="submit" data-confirm="Xác nhận tạo đoàn chung? Booking, thanh toán và chỗ từng tour không thay đổi.">Xác nhận ghép đoàn</button>
            </form>
            @empty <p>Không có tour đủ điều kiện ghép toàn bộ. Có thể giữ riêng, tìm thêm khách hoặc đề xuất đổi tour theo chính sách.</p>
            @endforelse
        </div>
    </section>
    @endif
    <section class="ad-card">
        <div class="ad-card__head"><h2>Đoàn chung đang hoạt động</h2></div>
        <div class="ad-card__body">
        @forelse ($groups as $g)
            <div style="padding:14px 0;border-bottom:1px solid #ddd">
                <b>Đoàn #{{ $g->groupId }}</b> · {{ \Carbon\Carbon::parse($g->departureDate)->format('d/m/Y') }} · tổng {{ $g->capacity }} chỗ @if(($g->fingerprintMode ?? 'strict')==='titles')<span class="ad-badge ad-badge--warn">Chỉ khớp tiêu đề</span>@endif
                @if($g->itineraryChanged)<p class="ad-badge ad-badge--warn">Cảnh báo: lịch trình đã đổi sau ghép. Cần kiểm tra và tách đoàn nếu không còn phù hợp.</p>@endif
                <ul>@foreach ($g->members as $m)<li>{{ $m->title }} — {{ $m->bookedSlots }} đã bán, {{ $m->heldSlots }} giữ, {{ $m->totalSlots }} chỗ</li>@endforeach</ul>
                @if($g->note)<p>Ghi chú: {{ $g->note }}</p>@endif
                <form method="POST" action="{{ route('admin.departures.grouping.disband', $g->groupId) }}">@csrf
                    <button class="ad-btn ad-btn--sm" type="submit" data-confirm="Tách đoàn #{{ $g->groupId }}? Booking vẫn giữ nguyên.">Tách đoàn</button>
                </form>
            </div>
        @empty <p>Chưa có đoàn chung.</p> @endforelse
        </div>
    </section>
@endif
@endsection
