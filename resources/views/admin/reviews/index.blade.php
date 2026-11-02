@extends('admin.layout')
@php
    $canDelete = in_array(session('adminRole'), ['admin', 'manager'], true);
    $distMax = max(1, (int) collect($dist)->max());
    $stars = fn($n) => str_repeat('★', (int) $n) . '<span class="off">' . str_repeat('★', 5 - (int) $n) . '</span>';
@endphp
@section('content')
    <div class="ad-head">
        <div>
            <h1>Đánh giá</h1>
            <p>Khách chỉ đánh giá được tour khi có đơn đã hoàn tất. Ẩn đánh giá không phù hợp thay vì xóa: khách không còn thấy và điểm trung bình không tính đánh giá đó{{ $canDelete ? '' : ' (xóa hẳn chỉ quản lý làm được)' }}.</p>
        </div>
    </div>

    <div class="ad-grid ad-grid--12" style="margin-bottom:20px">
        <section class="ad-card">
            <div class="ad-card__body" style="display:flex;gap:18px;align-items:center">
                <div style="text-align:center;min-width:110px">
                    <div style="font-size:40px;font-weight:700;color:var(--ad-ink);line-height:1">{{ $stats['avg_rating'] ?: '—' }}</div>
                    <div class="ad-stars" style="font-size:16px">{!! $stars(round($stats['avg_rating'])) !!}</div>
                    <div class="ad-note">{{ $stats['total'] }} đánh giá · {{ $stats['hidden'] }} đang ẩn</div>
                </div>
                <div style="flex:1">
                    @for ($s = 5; $s >= 1; $s--)
                        <a href="{{ route('admin.reviews', ['rating' => $rating === $s ? null : $s] + request()->only('search', 'visibility')) }}" style="display:flex;align-items:center;gap:8px;color:inherit;text-decoration:none;margin:3px 0;{{ $rating === $s ? 'font-weight:700' : '' }}">
                            <span style="width:26px">{{ $s }}★</span>
                            <span class="ad-meter__bar" style="flex:1"><i class="is-sand" style="width:{{ round(($dist[$s] ?? 0) / $distMax * 100) }}%"></i></span>
                            <span style="width:28px;text-align:right">{{ $dist[$s] ?? 0 }}</span>
                        </a>
                    @endfor
                </div>
            </div>
        </section>
        <section class="ad-card">
            <form class="ad-card__body ad-form ad-form--3" method="GET" action="{{ route('admin.reviews') }}" style="align-items:end">
                <div class="ad-fld"><label for="rv-q">Tìm</label><input id="rv-q" type="search" name="search" value="{{ $search }}" placeholder="Nội dung, tên khách, tên tour"></div>
                <div class="ad-fld"><label for="rv-v">Hiển thị</label>
                    <select id="rv-v" name="visibility"><option value="">Mọi đánh giá</option><option value="shown" @selected($visibility === 'shown')>Đang hiện</option><option value="hidden" @selected($visibility === 'hidden')>Đang ẩn</option></select></div>
                <div class="ad-fld"><label for="rv-r">Số sao</label>
                    <select id="rv-r" name="rating"><option value="">Mọi mức</option>@for ($s = 5; $s >= 1; $s--)<option value="{{ $s }}" @selected($rating === $s)>{{ $s }} sao</option>@endfor</select></div>
                <div class="span-all" style="display:flex;gap:8px;justify-content:flex-end">
                    @if ($search || $visibility || $rating)<a class="ad-btn ad-btn--ghost" href="{{ route('admin.reviews') }}">Bỏ lọc</a>@endif
                    <button class="ad-btn ad-btn--dark" type="submit"><i class="fa fa-filter"></i> Lọc</button>
                </div>
            </form>
        </section>
    </div>

    <div class="ad-card">
        @if ($reviews->count())
            <div class="ad-tablewrap">
                <table class="ad-table">
                    <thead><tr><th>Khách</th><th>Tour</th><th>Đánh giá</th><th>Ngày</th><th class="num">Thao tác</th></tr></thead>
                    <tbody>
                    @foreach ($reviews as $review)
                        <tr class="{{ !empty($review->isHidden) ? 'is-muted' : '' }}" data-href="{{ route('admin.reviews.show', $review->reviewId) }}">
                            <td><div class="ad-person" style="min-width:170px"><img src="{{ \App\Support\Avatar::url($review->user->avatar ?? null) }}" alt=""><span><b>{{ $review->user->fullName ?? 'Tài khoản đã xóa' }}</b><small>#{{ $review->reviewId }}</small></span></div></td>
                            <td style="max-width:240px">{{ \Illuminate\Support\Str::limit($review->tour->title ?? '—', 50) }}</td>
                            <td style="min-width:260px"><span class="ad-stars">{!! $stars($review->rating) !!}</span>
                                @if (!empty($review->isHidden))<span class="ad-badge ad-badge--mute ad-badge--plain" style="margin-left:6px">đang ẩn</span>@endif
                                <span class="ad-sub" style="color:var(--ad-text);font-size:13px">{{ \Illuminate\Support\Str::limit($review->comment ?: '(không có nội dung)', 110) }}</span></td>
                            <td class="nowrap">{{ date('d/m/Y', strtotime($review->timestamp)) }}<span class="ad-sub">{{ date('H:i', strtotime($review->timestamp)) }}</span></td>
                            <td>
                                <div class="ad-rowact">
                                    <form action="{{ route('admin.reviews.toggle', $review->reviewId) }}" method="POST" class="ad-inline-form">@csrf
                                        <button class="ad-btn ad-btn--sm" type="submit"><i class="fa {{ !empty($review->isHidden) ? 'fa-eye' : 'fa-eye-slash' }}"></i> {{ !empty($review->isHidden) ? 'Hiện' : 'Ẩn' }}</button></form>
                                    @if ($canDelete)
                                        <form action="{{ route('admin.reviews.destroy', $review->reviewId) }}" method="POST" class="ad-inline-form">@csrf @method('DELETE')
                                            <button class="ad-btn ad-btn--sm ad-btn--ghost ad-btn--icon" title="Xóa hẳn" aria-label="Xóa đánh giá #{{ $review->reviewId }}" data-confirm="Xóa hẳn đánh giá này? Nên dùng Ẩn để giữ lịch sử."><i class="fa fa-trash-o" style="color:var(--ad-bad)"></i></button></form>
                                    @endif
                                </div>
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
            @if ($reviews->hasPages())<div class="ad-pager"><span class="ad-note">{{ $reviews->total() }} đánh giá</span>{{ $reviews->links('admin.partials.pager') }}</div>@endif
        @else
            <div class="ad-empty"><i class="fa fa-star-o"></i><b>Không có đánh giá nào</b>{{ $search || $visibility || $rating ? 'Thử bỏ bớt điều kiện lọc.' : 'Đánh giá xuất hiện khi khách hoàn tất chuyến đi.' }}</div>
        @endif
    </div>
@endsection
