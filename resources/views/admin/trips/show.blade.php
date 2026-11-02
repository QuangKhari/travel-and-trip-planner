@extends('admin.layout')
@php
    $money = fn($v) => number_format((float) $v, 0, ',', '.') . ' đ';
    $typeLabel = ['destination' => ['fa-map-marker', 'Điểm đến'], 'place' => ['fa-map-pin', 'Địa điểm'], 'food' => ['fa-cutlery', 'Món ăn'], 'event' => ['fa-flag', 'Lễ hội'],
        'experience' => ['fa-compass', 'Trải nghiệm'], 'tour' => ['fa-suitcase', 'Tour'], 'note' => ['fa-sticky-note-o', 'Ghi chú'], 'transport' => ['fa-car', 'Di chuyển']];
    $hm = fn($m) => $m === null ? null : sprintf('%02d:%02d', intdiv((int) $m, 60), (int) $m % 60);
    $statuses = ['draft' => ['mute', 'Nháp'], 'planned' => ['info', 'Đã lên kế hoạch'], 'done' => ['ok', 'Đã đi']];
@endphp
@section('content')
    <div class="ad-head">
        <div>
            <a class="ad-back" href="{{ route('admin.trips') }}"><i class="fa fa-arrow-left"></i> Chuyến đi (Planner)</a>
            <h1>{{ $t->title }}</h1>
            <p><span class="ad-badge ad-badge--{{ $statuses[$t->status][0] ?? 'mute' }}">{{ $statuses[$t->status][1] ?? $t->status }}</span>
                <span class="ad-muted">· {{ $t->destName ?? 'Nhiều điểm đến' }}{{ $t->startDate ? ' · khởi hành ' . date('d/m/Y', strtotime($t->startDate)) : '' }}{{ $t->days ? ' · ' . $t->days . ' ngày' : '' }} · {{ $t->groupSize }} người · tạo {{ date('d/m/Y', strtotime($t->createdAt)) }}</span></p>
        </div>
        <div class="ad-actions">
            @if (!empty($t->shareToken))<a class="ad-btn" href="{{ route('planner.shared', ['token' => $t->shareToken]) }}" target="_blank" rel="noopener"><i class="fa fa-external-link"></i> Bản khách chia sẻ</a>@endif
            <form method="POST" action="{{ route('admin.trips.destroy', $t->tripId) }}">@csrf<button class="ad-btn ad-btn--danger" data-confirm="Xóa chuyến “{{ $t->title }}” của {{ $t->fullName }}? Không khôi phục được."><i class="fa fa-trash-o"></i> Xóa (vi phạm)</button></form>
        </div>
    </div>
    <div class="ad-flash ad-flash--info"><i class="fa fa-lock"></i><div>Chế độ chỉ xem: chuyến đi là dữ liệu riêng của khách. Nhân viên dùng trang này để tư vấn; mọi thay đổi do khách tự làm trong Trip Planner.</div></div>

    <div class="ad-grid ad-grid--side">
        <div>
            @forelse ($days as $day => $rows)
                <section class="ad-card">
                    <div class="ad-card__head"><h2>Ngày {{ $day }}{{ $t->startDate ? ' · ' . \Carbon\Carbon::parse($t->startDate)->addDays($day - 1)->format('d/m/Y') : '' }}</h2>
                        <small>{{ count($rows) }} mục{{ $rows->sum('cost') ? ' · ' . $money($rows->sum('cost')) : '' }}</small></div>
                    <ul class="ad-timeline ad-card__body" style="margin:0">
                        @foreach ($rows as $it)
                            @php $ref = $names[$it->refType . ':' . $it->refId] ?? null; [$ic, $lb] = $typeLabel[$it->refType] ?? ['fa-circle-o', $it->refType]; @endphp
                            <li style="--c:{{ $it->refType === 'tour' ? 'var(--ad-sand)' : 'var(--ad-primary)' }}">
                                <div class="ad-timeline__t"><i class="fa {{ $ic }}" style="color:var(--ad-mute);width:16px"></i>
                                    {{ $it->label ?: ($ref['name'] ?? ($it->refId ? $lb . ' #' . $it->refId . ' (đã ẩn / xóa)' : $lb)) }}
                                    @if ($it->isLocked)<i class="fa fa-lock ad-muted" title="Khách đã khóa mục này"></i>@endif</div>
                                <div class="ad-timeline__m">{{ $lb }}{{ $hm($it->startMin) ? ' · ' . $hm($it->startMin) : '' }}{{ $it->durationMin ? ' · ' . $it->durationMin . ' phút' : '' }}{{ $it->cost ? ' · ' . $money($it->cost) : '' }}{{ !empty($ref['sub']) ? ' · ' . $ref['sub'] : '' }}</div>
                                @if ($it->note)<div class="ad-bubble" style="padding:6px 10px;font-size:13px">{{ $it->note }}</div>@endif
                            </li>
                        @endforeach
                    </ul>
                </section>
            @empty
                <div class="ad-card"><div class="ad-empty"><i class="fa fa-map-o"></i><b>Chuyến đi chưa có mục nào</b></div></div>
            @endforelse
        </div>
        <aside>
            <section class="ad-card">
                <div class="ad-card__head"><h2>Chủ chuyến</h2></div>
                <div class="ad-card__body"><a class="ad-person" href="{{ route('admin.users.show', $t->userId) }}" style="color:inherit"><img src="{{ \App\Support\Avatar::url($t->avatar) }}" alt=""><span><b>{{ $t->fullName }}</b><small>{{ $t->email }}</small></span></a></div>
            </section>
            <section class="ad-card">
                <div class="ad-card__head"><h2>Tổng quan</h2></div>
                <div class="ad-card__body"><dl class="ad-kv ad-kv--tight">
                    <dt>Ngân sách</dt><dd>{{ $t->budget ? $money($t->budget) : '—' }}</dd>
                    <dt>Chi phí ước tính</dt><dd>{{ $cost ? $money($cost) : '—' }}</dd>
                    <dt>Số mục</dt><dd>{{ count($items) }}</dd>
                    <dt>Nguồn</dt><dd>{{ ['manual' => 'Tự lập', 'ai' => 'La bàn AI', 'tour' => 'Từ tour', 'itinerary' => 'Từ lịch trình mẫu', 'copy' => 'Nhân bản'][$t->source ?? 'manual'] ?? $t->source }}</dd>
                    <dt>Chia sẻ link</dt><dd>{{ !empty($t->shareToken) ? 'Đang bật' : 'Tắt' }}</dd>
                    @if (!empty($t->interests))<dt>Sở thích</dt><dd>{{ \Illuminate\Support\Str::limit(is_string($t->interests) ? implode(', ', (array) (json_decode($t->interests, true) ?: [$t->interests])) : '', 80) }}</dd>@endif
                    @if (!empty($t->notes))<dt>Ghi chú</dt><dd>{{ \Illuminate\Support\Str::limit($t->notes, 160) }}</dd>@endif
                </dl></div>
            </section>
            <section class="ad-card">
                <div class="ad-card__head"><h2>Thành viên</h2><small>{{ count($members) }}</small></div>
                @if (count($members))
                    <ul class="ad-todo">@foreach ($members as $m)<li><a href="{{ route('admin.users.show', $m->userId) }}"><span class="ad-todo__t">{{ $m->fullName }}<small>{{ $m->email }} · {{ $m->role === 'edit' ? 'được sửa' : 'chỉ xem' }}</small></span><i class="fa fa-angle-right"></i></a></li>@endforeach</ul>
                @else<div class="ad-card__body ad-note">Chuyến cá nhân, chưa mời ai.</div>@endif
            </section>
            @if (count($tickets))
                <section class="ad-card">
                    <div class="ad-card__head"><h2>Yêu cầu tư vấn</h2></div>
                    <ul class="ad-todo">@foreach ($tickets as $tk)<li><a href="{{ route('admin.support.show', $tk->ticketId) }}"><span class="ad-todo__t">{{ $tk->subject }}<small>{{ $tk->code }} · {{ \App\Services\SupportTicketService::STATUS[$tk->status][1] ?? $tk->status }}</small></span><i class="fa fa-angle-right"></i></a></li>@endforeach</ul>
                </section>
            @endif
        </aside>
    </div>
@endsection
