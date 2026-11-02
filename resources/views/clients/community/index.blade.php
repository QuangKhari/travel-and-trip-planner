@include('clients.blocks.header')
<link rel="stylesheet" href="{{ asset('clients/assets/css/community.css') }}?v=1">
@php
    use App\Services\Planner\TripCommunity as C;
    $money = fn($v) => $v >= 1_000_000 ? rtrim(rtrim(number_format($v / 1_000_000, 1, ',', '.'), '0'), ',') . ' triệu' : number_format((float) $v, 0, ',', '.') . 'đ';
    $qs = fn(array $over) => route('community.index', array_filter(array_merge($f, $over), fn($v) => $v !== null && $v !== ''));
@endphp
<main class="cm">
    <div class="cm-wrap">
        <h1>Cộng đồng hành trình</h1>
        <p class="cm-lead">Lịch trình thật do thành viên Travela lên kế hoạch và chia sẻ. Xem từng ngày, thích để lưu lại, hoặc dùng làm khung cho chuyến đi của bạn rồi sửa theo ý mình.</p>
        @if (session('error'))<div class="cm-err" role="alert" style="margin-bottom:14px">{{ session('error') }}</div>@endif

        <form class="cm-filter" method="GET" action="{{ route('community.index') }}" role="search">
            <input type="hidden" name="tab" value="{{ $f['tab'] }}">
            <input class="cm-q" type="search" name="q" value="{{ $f['q'] ?? '' }}" placeholder="Tìm theo tên chuyến, điểm đến…" aria-label="Từ khóa">
            <select name="dest" aria-label="Điểm đến" class="no-nice">
                <option value="">Mọi điểm đến</option>
                @foreach ($destinations as $d)<option value="{{ $d->destinationId }}" @selected(($f['dest'] ?? null) == $d->destinationId)>{{ $d->name }} ({{ $d->n }})</option>@endforeach
            </select>
            <select name="days" aria-label="Số ngày" class="no-nice">
                <option value="">Số ngày</option>
                @foreach (C::DAY_RANGES as $k => $l)<option value="{{ $k }}" @selected(($f['days'] ?? null) === $k)>{{ $l }}</option>@endforeach
            </select>
            <select name="budget" aria-label="Ngân sách mỗi người" class="no-nice">
                <option value="">Ngân sách / người</option>
                @foreach (C::BUDGET_RANGES as $k => $b)<option value="{{ $k }}" @selected(($f['budget'] ?? null) === $k)>{{ $b[2] }}</option>@endforeach
            </select>
            <select name="interest" aria-label="Sở thích" class="no-nice">
                <option value="">Sở thích</option>
                @foreach ($interests as $k => $i)<option value="{{ $k }}" @selected(($f['interest'] ?? null) === $k)>{{ $i['label'] ?? $k }}</option>@endforeach
            </select>
            <button type="submit">Lọc</button>
        </form>

        <nav class="cm-tabs" aria-label="Sắp xếp">
            @foreach (C::TABS as $k => $l)
                <a href="{{ $qs(['tab' => $k, 'page' => null]) }}" class="{{ $f['tab'] === $k ? 'is-on' : '' }}" @if ($f['tab'] === $k) aria-current="page" @endif>{{ $l }}</a>
            @endforeach
            <span class="cm-count">{{ number_format($feed['total'], 0, ',', '.') }} hành trình</span>
        </nav>

        @if (!$feed['rows'])
            <div class="cm-empty">
                @if (array_filter(\Illuminate\Support\Arr::except($f, ['tab'])))
                    Không có hành trình nào khớp bộ lọc. <a href="{{ route('community.index', ['tab' => $f['tab']]) }}">Bỏ lọc</a>
                @else
                    Chưa có hành trình nào được chia sẻ. Hãy là người đầu tiên: mở một chuyến trong <a href="{{ route('planner') }}">Chuyến đi của tôi</a> và chọn “Chia sẻ lên Cộng đồng”.
                @endif
            </div>
        @else
            <div class="cm-grid">
                @foreach ($feed['rows'] as $r)
                    @php $cover = $r->destCover ? \App\Support\DiscoverImage::url($r->destCover) : null; @endphp
                    <a class="cm-card" href="{{ route('community.show', $r->tripId) }}">
                        <div class="cm-card__ph" @if ($cover) style="background-image:url('{{ $cover }}')" @endif>
                            @unless ($cover)<span class="cm-card__ini">{{ mb_strtoupper(mb_substr($r->destName ?: $r->title, 0, 1)) }}</span>@endunless
                            <span class="cm-card__days">{{ $r->days }} ngày</span>
                        </div>
                        <div class="cm-card__b">
                            <h2>{{ $r->title }}</h2>
                            <p>{{ $r->summary ?: (($r->destName ? $r->destName . ' · ' : '') . $r->placedCount . ' điểm dừng · ' . $r->groupSize . ' người') }}</p>
                            <div class="cm-tags">
                                @if ($r->destName)<span class="cm-tag">{{ $r->destName }}</span>@endif
                                @if ($r->perPerson)<span class="cm-tag">≈ {{ $money($r->perPerson) }}/người</span>@endif
                                @foreach (array_slice($r->interestList, 0, 2) as $i)<span class="cm-tag">{{ $interests[$i]['label'] ?? $i }}</span>@endforeach
                            </div>
                            <div class="cm-meta">
                                <span class="cm-by"><img src="{{ \App\Support\Avatar::url($r->avatar) }}" alt="" loading="lazy"><span>{{ $r->author }}</span></span>
                                <span title="Lượt thích"><i class="far fa-heart" aria-hidden="true"></i> <b>{{ $r->likeCount }}</b></span>
                                <span title="Lượt dùng lại"><i class="far fa-copy" aria-hidden="true"></i> <b>{{ $r->cloneCount }}</b></span>
                            </div>
                        </div>
                    </a>
                @endforeach
            </div>
            @if ($feed['pages'] > 1)
                <nav class="cm-pager" aria-label="Phân trang">
                    @for ($p = 1; $p <= $feed['pages']; $p++)
                        @if ($p === $feed['page'])<span class="is-on" aria-current="page">{{ $p }}</span>@else<a href="{{ $qs(['page' => $p]) }}">{{ $p }}</a>@endif
                    @endfor
                </nav>
            @endif
        @endif
    </div>
</main>
@include('clients.blocks.footer')
