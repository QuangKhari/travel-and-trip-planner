@include('clients.blocks.header')
@include('clients.support._style')
@php $st = ['open' => 'Chờ báo giá', 'quoted' => 'Đã có báo giá', 'accepted' => 'Bạn đã nhận', 'declined' => 'Bạn đã từ chối', 'cancelled' => 'Đã hủy']; @endphp
<main class="sp"><div class="sp-wrap">
    <header class="sp-head"><h1>Báo giá tour riêng</h1>
        <p>Gửi lịch bạn tự lên trong Trip Planner, Travela báo giá trọn gói (xe, hướng dẫn viên, khách sạn, vé) cho riêng nhóm bạn. Gửi từ menu ⋮ của trang chuyến đi.</p></header>
    <section class="sp-card">
        @if ($rows->isEmpty())
            <div class="sp-card__b">Chưa có yêu cầu nào. Mở một chuyến đi → menu ⋮ → “Nhờ Travela báo giá tour riêng cho nhóm”.</div>
        @else
            <ul class="sp-list">@foreach ($rows as $r)
                <li><a href="{{ route('quotes.show', $r->quoteId) }}"><span><b>#{{ $r->quoteId }} · {{ $r->title }}</b>
                    <small>{{ $r->groupSize }} người · gửi {{ \Carbon\Carbon::parse($r->createdAt)->format('d/m/Y') }}@if ($r->pricePerPerson) · {{ number_format($r->pricePerPerson, 0, ',', '.') }}đ/người @endif</small></span>
                    <span class="sp-badge sp-badge--{{ $r->status === 'quoted' ? 'in_progress' : 'open' }}">{{ $st[$r->status] ?? $r->status }}</span></a></li>
            @endforeach</ul>
        @endif
    </section>
</div></main>
@include('clients.blocks.footer')
