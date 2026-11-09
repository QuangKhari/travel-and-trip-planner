@extends('admin.layout')
@section('content')
    <div class="ad-head">
        <div>
            <h1>Sự cố #{{ $inc->incidentId }}: {{ $visible ? $inc->title : 'Sự cố bảo mật' }}</h1>
            <p><span class="ad-badge ad-badge--{{ ['critical' => 'bad', 'high' => 'bad', 'medium' => 'warn', 'low' => 'mute'][$inc->severity] ?? 'mute' }}">{{ $severities[$inc->severity] ?? $inc->severity }}</span>
                {{ $types[$inc->type] ?? $inc->type }} · <b>{{ $statuses[$inc->status] ?? $inc->status }}</b>@if ($inc->confidential) · <i class="fa fa-lock"></i> Bảo mật @endif</p>
        </div>
        <a class="ad-btn" href="{{ route('admin.incidents') }}">← Danh sách</a>
    </div>

    <div style="display:grid;grid-template-columns:minmax(0,2fr) minmax(0,1fr);gap:20px;align-items:start">
        <div>
            <section class="ad-card" style="margin-bottom:20px">
                <div class="ad-card__head"><h2>Chi tiết</h2></div>
                <div class="ad-card__body">
                    @if ($visible)
                        <p style="white-space:pre-line">{{ $inc->description ?: 'Chưa có mô tả.' }}</p>
                        @if ($inc->resolution)<p><b>Kết quả xử lý:</b> {{ $inc->resolution }}</p>@endif
                    @else
                        <p><i class="fa fa-lock"></i> Nội dung bị hạn chế: chỉ người báo và quản trị viên cấp cao xem được.</p>
                    @endif
                    <dl class="ad-kv ad-kv--tight">
                        <dt>Tour</dt><dd>@if ($tour)<a href="{{ route('tour-detail', ['id' => $tour->tourId]) }}" target="_blank" rel="noopener">{{ $tour->title }}</a>@else Chưa gắn @endif</dd>
                        <dt>Ngày đi</dt><dd>{{ $dep ? date('d/m/Y', strtotime($dep->departureDate)) . ' (lịch #' . $dep->availabilityId . ')' : 'Chưa gắn lịch' }}</dd>
                        <dt>Khách bị ảnh hưởng</dt><dd>{{ $affected->count() }} đơn · {{ $people }} người</dd>
                    </dl>
                </div>
            </section>

            <section class="ad-card" style="margin-bottom:20px">
                <div class="ad-card__head"><h2>Nhật ký xử lý</h2></div>
                <div class="ad-card__body">
                    <ul class="ad-timeline">
                        @foreach ($events as $e)
                            <li><div class="ad-timeline__t">{{ ['created' => 'Ghi nhận', 'status' => 'Trạng thái', 'note' => 'Ghi chú', 'notify' => 'Báo khách', 'plan' => 'Departure Rescue', 'system' => 'Hệ thống'][$e->kind] ?? $e->kind }}</div>
                                <div>{{ $visible || in_array($e->kind, ['created', 'status', 'plan'], true) ? $e->message : '(nội dung bảo mật)' }}</div>
                                <div class="ad-timeline__m">{{ date('H:i d/m/Y', strtotime($e->createdAt)) }}{{ $e->userName ? ' · ' . $e->userName : '' }}</div></li>
                        @endforeach
                    </ul>
                    <form method="POST" action="{{ route('admin.incidents.note', $inc->incidentId) }}" style="display:flex;gap:8px;margin-top:12px">@csrf
                        <input name="note" required minlength="3" maxlength="1500" placeholder="Ghi chú xử lý…" style="flex:1"><button class="ad-btn" type="submit">Thêm</button></form>
                </div>
            </section>
        </div>

        <div>
            <section class="ad-card" style="margin-bottom:20px">
                <div class="ad-card__head"><h2>Trạng thái</h2></div>
                <div class="ad-card__body">
                    @if (!empty($flow[$inc->status]))
                        <form method="POST" action="{{ route('admin.incidents.status', $inc->incidentId) }}" class="ad-form">@csrf
                            <div class="ad-fld"><label>Chuyển sang</label><select name="status">@foreach ($flow[$inc->status] as $s)<option value="{{ $s }}">{{ $statuses[$s] }}</option>@endforeach</select></div>
                            <div class="ad-fld"><label>Ghi chú / kết quả (bắt buộc khi "Đã xử lý")</label><textarea name="note" rows="3" maxlength="1500"></textarea></div>
                            <button class="ad-btn ad-btn--primary" type="submit">Cập nhật</button>
                        </form>
                    @else
                        <p>Sự cố đã đóng.</p>
                    @endif
                </div>
            </section>

            @if ($dep && !in_array($inc->status, ['closed'], true))
                <section class="ad-card" style="margin-bottom:20px">
                    <div class="ad-card__head"><h2>Ảnh hưởng đến lịch trình đã cam kết?</h2></div>
                    <div class="ad-card__body">
                        @if ($plan)
                            <p>Đã có phương án <b>#{{ $plan->planId }}</b> ({{ $plan->type }} · {{ $plan->status }}).</p>
                            <a class="ad-btn ad-btn--sm" href="{{ route('admin.departures.rescue') }}">Mở Departure Rescue</a>
                        @else
                            <p>Nếu phải dời ngày, đổi tour hoặc hủy khởi hành, lập phương án để xin xác nhận khách và xử lý hoàn tiền. Hủy khởi hành vì sự cố hoàn 100% và không cần khách đồng ý.</p>
                            <a class="ad-btn ad-btn--primary ad-btn--sm" href="{{ route('admin.departures.rescue', ['availabilityId' => $dep->availabilityId, 'incidentId' => $inc->incidentId]) }}">Lập phương án xử lý</a>
                        @endif
                    </div>
                </section>

                <section class="ad-card">
                    <div class="ad-card__head"><h2>Báo cho khách</h2></div>
                    <div class="ad-card__body">
                        <form method="POST" action="{{ route('admin.incidents.notify', $inc->incidentId) }}" class="ad-form">@csrf
                            <div class="ad-fld"><label>Nội dung email gửi {{ $affected->count() }} đơn</label><textarea name="message" rows="4" required minlength="10" maxlength="1500" placeholder="Nêu ngắn gọn điều gì xảy ra, ảnh hưởng và bước tiếp theo."></textarea></div>
                            <button class="ad-btn" type="submit" data-confirm="Gửi email này cho tất cả khách của lịch khởi hành?">Gửi email</button>
                        </form>
                    </div>
                </section>
            @endif
        </div>
    </div>
@endsection
