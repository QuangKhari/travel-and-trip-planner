@extends('admin.layout')
@php
    $canDelete = in_array(session('adminRole'), ['admin', 'manager'], true);
    $stars = fn($n) => str_repeat('★', (int) $n) . '<span class="off">' . str_repeat('★', 5 - (int) $n) . '</span>';
@endphp
@section('content')
    <div class="ad-head">
        <div>
            <a class="ad-back" href="{{ route('admin.reviews') }}"><i class="fa fa-arrow-left"></i> Đánh giá</a>
            <h1>Đánh giá #{{ $review->reviewId }}</h1>
            <p><span class="ad-stars" style="font-size:16px">{!! $stars($review->rating) !!}</span> {{ $review->rating }}/5 ·
                {{ date('H:i d/m/Y', strtotime($review->timestamp)) }}
                @if (!empty($review->isHidden))<span class="ad-badge ad-badge--mute">Đang ẩn</span>@else<span class="ad-badge ad-badge--ok">Đang hiện</span>@endif</p>
        </div>
        <div class="ad-actions">
            <form action="{{ route('admin.reviews.toggle', $review->reviewId) }}" method="POST">@csrf
                <button class="ad-btn {{ !empty($review->isHidden) ? 'ad-btn--primary' : '' }}" type="submit"><i class="fa {{ !empty($review->isHidden) ? 'fa-eye' : 'fa-eye-slash' }}"></i> {{ !empty($review->isHidden) ? 'Hiện lại' : 'Ẩn khỏi trang khách' }}</button></form>
            @if ($canDelete)
                <form action="{{ route('admin.reviews.destroy', $review->reviewId) }}" method="POST">@csrf @method('DELETE')
                    <button class="ad-btn ad-btn--danger" data-confirm="Xóa hẳn đánh giá này?"><i class="fa fa-trash-o"></i> Xóa hẳn</button></form>
            @endif
        </div>
    </div>
    <div class="ad-grid ad-grid--21">
        <section class="ad-card">
            <div class="ad-card__head"><h2>Nội dung</h2></div>
            <div class="ad-card__body">
                <blockquote style="margin:0;padding:16px 20px;border-left:4px solid var(--ad-sand);background:#fffbeb;border-radius:0 12px 12px 0;font-size:15px;color:var(--ad-ink);white-space:pre-wrap">{{ $review->comment ?: '(Khách chỉ chấm sao, không viết nội dung)' }}</blockquote>
                @if (!empty($review->isHidden))
                    <p class="ad-note" style="margin:12px 0 0">Đánh giá đang ẩn: không hiện ở trang tour và không tính vào điểm trung bình.</p>
                @endif
            </div>
        </section>
        <aside>
            <section class="ad-card">
                <div class="ad-card__head"><h2>Khách hàng</h2></div>
                <div class="ad-card__body">
                    <div class="ad-person"><img src="{{ \App\Support\Avatar::url($review->user->avatar ?? null) }}" alt=""><span><b>{{ $review->user->fullName ?? 'Tài khoản đã xóa' }}</b><small>{{ $review->user->email ?? '' }}</small></span></div>
                </div>
            </section>
            <section class="ad-card">
                <div class="ad-card__head"><h2>Tour</h2></div>
                <div class="ad-card__body">
                    <b style="color:var(--ad-ink)">{{ $review->tour->title ?? '—' }}</b>
                    @if (isset($review->tour->priceAdult))<div class="ad-note">Giá người lớn {{ number_format($review->tour->priceAdult, 0, ',', '.') }} đ</div>@endif
                    @if ($review->tour)<a class="ad-btn ad-btn--sm" style="margin-top:10px" href="{{ route('tour-detail', ['id' => $review->tourId]) }}" target="_blank" rel="noopener"><i class="fa fa-external-link"></i> Trang tour</a>@endif
                </div>
            </section>
        </aside>
    </div>
@endsection
