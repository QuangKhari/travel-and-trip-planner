@include('clients.blocks.header')
<link rel="stylesheet" href="{{ asset('clients/assets/css/planner.css') }}?v=21">
<link rel="stylesheet" href="{{ asset('clients/assets/css/planner-flow.css') }}?v=3">
<link rel="stylesheet" href="{{ asset('clients/assets/css/nature-luxury-planner.css') }}?v=bl20261104">
<link rel="stylesheet" href="{{ asset('clients/assets/css/travela-nature-all-pages.css') }}?v=bl20261104">

{{-- /len-ke-hoach/chon-diem-den. Biến: $inspirations, $picked, $month, $days, $group, $budget, $maxDays, $result (null nếu chưa chọn tháng), $now, $openMode --}}
@php $cur = $month ?? $now; @endphp
<main class="tp tpf">
    <div class="container">
        <header class="tpf-hero">
            <p class="tpf-back"><a href="{{ route('planner') }}"><i class="fas fa-arrow-left" aria-hidden="true"></i> Chuyến đi của tôi</a></p>
            <p class="tpf-eyebrow">Tìm cảm hứng</p>
            <h1>Chưa biết đi đâu?</h1>
            <p class="tpf-lead">Chọn tháng và điều bạn đang mong. Travela xếp điểm đến theo mùa, khí hậu, lễ hội và tour có thật, kèm lý do.</p>
        </header>

        <form method="GET" action="{{ route('planner.discover') }}" class="tpf-step" aria-labelledby="tpDiscH">
            <h2 id="tpDiscH"><span class="tpf-num" aria-hidden="true"><i class="fas fa-compass"></i></span> Bạn muốn gì ở chuyến đi này?</h2>
            <fieldset class="tpf-toggles" style="margin-top:0">
                <legend>Tháng đi</legend>
                @for ($m = 1; $m <= 12; $m++)
                    <label class="tpf-toggle"><input type="radio" name="thang" value="{{ $m }}" @checked($cur === $m) required><span>Tháng {{ $m }}</span></label>
                @endfor
            </fieldset>
            <fieldset class="tpf-toggles">
                <legend>Bạn đang mong điều gì? <small>(chọn vài mục)</small></legend>
                @foreach ($inspirations as $ins)
                    <label class="tpf-toggle" @if ($ins->tagline) title="{{ $ins->tagline }}" @endif><input type="checkbox" name="cam-hung[]" value="{{ $ins->slug }}" @checked(in_array($ins->slug, $picked, true))><span><i class="fas {{ $ins->icon ?: 'fa-star' }}" aria-hidden="true"></i> {{ $ins->name }}</span></label>
                @endforeach
            </fieldset>
            <details class="tpf-more" @if ($days || $group || $budget) open @endif>
                <summary>Thêm số ngày, số người, ngân sách <small>(không bắt buộc)</small></summary>
                <div class="tpf-row" style="margin-top:10px">
                    <label class="tpf-field"><span>Số ngày</span><input class="tpf-inp" type="number" name="ngay" min="1" max="{{ $maxDays }}" value="{{ $days }}" placeholder="vd 3"></label>
                    <label class="tpf-field"><span>Số người</span><input class="tpf-inp" type="number" name="nguoi" min="1" max="50" value="{{ $group }}" placeholder="vd 2"></label>
                    <label class="tpf-field"><span>Ngân sách cả nhóm (đ)</span><input class="tpf-inp" type="number" name="ngan-sach" min="0" step="100000" value="{{ $budget ? (int) $budget : '' }}" placeholder="vd 5000000"></label>
                </div>
            </details>
            <button type="submit" class="tp-btn tpf-cta" style="width:auto;margin-top:12px">Gợi ý điểm đến <i class="fas fa-arrow-right" aria-hidden="true"></i></button>
        </form>

        @if ($result)
            <section aria-labelledby="tpDiscR">
                <h2 id="tpDiscR" class="tpf-h2">Hợp nhất cho tháng {{ $result['month'] }}</h2>
                @if ($result['budgetNote'])<p class="tp-muted">{{ $result['budgetNote'] }}</p>@endif
                @if (!$result['items'])
                    <p class="tp-muted">Chưa có điểm đến nào để gợi ý. Bạn có thể <a href="{{ route('planner') }}">tạo chuyến đi trống</a> rồi tự thêm địa điểm.</p>
                @endif
                <ol class="tpf-find" style="list-style:none;padding:0">
                    @foreach ($result['items'] as $i => $it)
                        <li class="tpf-fcard">
                            <div class="tpf-fcard__img">
                                @if (!empty($it['img']))<img src="{{ $it['img'] }}" alt="" loading="lazy" onerror="this.remove()">@endif
                                <span class="tpf-fcard__score" title="Điểm chỉ để xếp thứ tự, không phải đánh giá">#{{ $i + 1 }} · hợp {{ $it['score'] }}/100</span>
                            </div>
                            <div class="tpf-fcard__b">
                                <h3><a href="{{ $it['url'] }}">{{ $it['name'] }}</a><small>{{ $it['province'] }}</small>
                                    <span class="tpf-badge {{ $it['plannerReady'] ? 'is-ready' : 'is-open' }}">{{ $it['plannerReady'] ? 'Đủ dữ liệu biên tập' : 'Đề xuất mở rộng' }}</span></h3>
                                <ul class="tpf-fcard__why">
                                    @foreach ($it['reasons'] as $r)<li>{{ $r }}</li>@endforeach
                                    @foreach ($it['warns'] as $w)<li class="is-warn">{{ $w }}</li>@endforeach
                                </ul>
                                <p class="tpf-fcard__act">
                                    @if ($it['plannerReady'] || $openMode)
                                        <a class="tp-btn" href="{{ $it['planUrl'] }}"><i class="fas fa-magic" aria-hidden="true"></i> {{ $it['plannerReady'] ? 'Lên lịch trình tháng ' . $result['month'] : 'Đề xuất mở rộng' }}</a>
                                    @endif
                                    <a class="tp-btn tp-btn--ghost" href="{{ $it['monthUrl'] }}">Xem tháng {{ $result['month'] }} ở đây</a>
                                </p>
                            </div>
                        </li>
                    @endforeach
                </ol>
                <p class="tp-muted tp-small">Xếp theo độ hợp với cảm hứng bạn chọn và mùa đi. Tháng không nên đi được đưa xuống cuối kèm cảnh báo.
                    @if ($openMode) “Đề xuất mở rộng”: điểm đến chưa đủ nội dung biên tập, lịch sẽ bổ sung từ bản đồ OpenStreetMap và ghi rõ.@endif</p>
            </section>
        @endif
    </div>
</main>

@include('clients.blocks.footer')
