@include('clients.blocks.header')
{{-- /le-hoi — biến: $items, $filters, $total, $regions, $months --}}
<main class="gx-page" id="main">
    <div class="gx-wrap">
        @include('clients.partials.gx.crumb', ['crumbs' => [['Trang chủ', route('home')], ['Lễ hội', null]]])
        <header class="gx-head">
            <p class="gx-eyebrow">Ngày dương lịch tính từ quy tắc âm lịch</p>
            <h1>Lễ hội</h1>
            <p class="gx-lead">Hiển thị đợt gần nhất sắp tới. Bấm vào một lễ hội để xem ngày của 3 năm tới.</p>
        </header>
        <div class="gx-chips" role="group" aria-label="Chế độ xem">
            <a class="gx-chip is-on" href="{{ route('events.index') }}" aria-current="true">Thẻ</a>
            <a class="gx-chip" href="{{ route('events.calendar') }}">Lịch tháng</a>
        </div>
        @include('clients.partials.gx.filters', [
            'action' => route('events.index'),
            'filters' => $filters,
            'search' => 'Tìm lễ hội',
            'fields' => [['mien', 'Miền', $regions], ['thang', 'Diễn ra vào', $months], ['loai', 'Loại', ['am' => 'Âm lịch', 'duong' => 'Dương lịch']]],
        ])
        <p class="gx-count" aria-live="polite">{{ $total }} lễ hội</p>
        @if ($items->isEmpty())
            <div class="gx-empty">Chưa có lễ hội nào khớp. Nội dung chỉ hiện sau khi được duyệt.</div>
        @else
            <div class="gx-grid">
                @foreach ($items as $c)
                    @include('clients.partials.gx.card', ['c' => $c, 'loading' => $loop->index < 3 ? 'eager' : 'lazy'])
                @endforeach
            </div>
            @include('clients.partials.gx.pager', ['items' => $items])
        @endif
    </div>
</main>
@include('clients.blocks.footer')
