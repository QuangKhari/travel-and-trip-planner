@include('clients.blocks.header')
<link rel="stylesheet" href="{{ asset('clients/assets/css/planner.css') }}?v=7">

{{-- /len-ke-hoach/chon-diem-den. Biến: $inspirations, $picked, $month, $days, $group, $budget, $maxDays, $result (null nếu chưa chọn tháng), $now --}}
<main class="tp">
    <div class="container">
        <header class="tp-head">
            <p class="tp-back"><a href="{{ route('planner') }}">← Chuyến đi của tôi</a></p>
            <h1>Chưa biết đi đâu?</h1>
            <p>Chọn tháng và điều bạn đang mong, Travela xếp điểm đến theo mùa, khí hậu, lễ hội và tour có thật, kèm lý do.</p>
        </header>

        <section class="tp-card" aria-labelledby="tpDiscH">
            <h2 id="tpDiscH">Bạn muốn gì ở chuyến đi này?</h2>
            <form method="GET" action="{{ route('planner.discover') }}" class="tp-form">
                <div class="tp-row">
                    <label>Tháng đi
                        <select class="no-nice" name="thang" required>
                            <option value="">— Chọn tháng —</option>
                            @for ($m = 1; $m <= 12; $m++)
                                <option value="{{ $m }}" @selected(($month ?? $now) === $m && ($month !== null || $m === $now))>Tháng {{ $m }}</option>
                            @endfor
                        </select>
                    </label>
                    <label>Số ngày (không bắt buộc)
                        <input type="number" name="ngay" min="1" max="{{ $maxDays }}" value="{{ $days }}">
                    </label>
                </div>
                <div class="tp-row">
                    <label>Số người (không bắt buộc)
                        <input type="number" name="nguoi" min="1" max="50" value="{{ $group }}">
                    </label>
                    <label>Ngân sách cả nhóm, đ (không bắt buộc)
                        <input type="number" name="ngan-sach" min="0" step="100000" value="{{ $budget ? (int) $budget : '' }}">
                    </label>
                </div>
                <fieldset>
                    <legend>Bạn đang mong điều gì?</legend>
                    @foreach ($inspirations as $ins)
                        <label class="tp-check"><input type="checkbox" name="cam-hung[]" value="{{ $ins->slug }}" @checked(in_array($ins->slug, $picked, true))> {{ $ins->name }}@if ($ins->tagline) <span class="tp-muted tp-small">· {{ $ins->tagline }}</span>@endif</label>
                    @endforeach
                </fieldset>
                <button type="submit" class="tp-btn">Gợi ý điểm đến</button>
            </form>
        </section>

        @if ($result)
            <section class="tp-card" aria-labelledby="tpDiscR">
                <h2 id="tpDiscR">Hợp nhất cho tháng {{ $result['month'] }}</h2>
                @if ($result['budgetNote'])<p class="tp-muted">{{ $result['budgetNote'] }}</p>@endif
                @if (!$result['items'])
                    <p class="tp-muted">Chưa có điểm đến nào đủ dữ liệu để gợi ý. Bạn có thể <a href="{{ route('planner') }}">tạo chuyến đi trống</a> rồi tự thêm địa điểm.</p>
                @endif
                <ol class="tp-find">
                    @foreach ($result['items'] as $it)
                        <li class="tp-find__item">
                            <div class="tp-find__head">
                                <h3><a href="{{ $it['url'] }}">{{ $it['name'] }}</a> <span class="tp-muted tp-small">{{ $it['province'] }}</span></h3>
                                <span class="tp-find__score" title="Điểm chỉ để xếp thứ tự, không phải đánh giá">Độ hợp {{ $it['score'] }}/100</span>
                            </div>
                            @if ($it['reasons'])
                                <ul class="tp-why">@foreach ($it['reasons'] as $r)<li class="tp-why--good">{{ $r }}</li>@endforeach</ul>
                            @endif
                            @if ($it['warns'])
                                <ul class="tp-why">@foreach ($it['warns'] as $w)<li class="tp-why--warn">{{ $w }}</li>@endforeach</ul>
                            @endif
                            <p class="tp-find__act">
                                @if ($it['plannerReady'])
                                    <a class="tp-btn" href="{{ $it['planUrl'] }}">Lên lịch trình tháng {{ $result['month'] }}</a>
                                @else
                                    <span class="tp-muted tp-small">Chưa đủ dữ liệu để tự xếp lịch, bạn vẫn có thể tạo chuyến đi trống.</span>
                                @endif
                                <a class="tp-btn tp-btn--ghost" href="{{ $it['monthUrl'] }}">Xem tháng {{ $result['month'] }} ở đây</a>
                            </p>
                        </li>
                    @endforeach
                </ol>
                <p class="tp-muted tp-small">Xếp theo độ hợp với cảm hứng bạn chọn và mùa đi. Tháng không nên đi được đưa xuống cuối kèm cảnh báo. Điểm chỉ để xếp thứ tự.</p>
            </section>
        @endif
    </div>
</main>

@include('clients.blocks.footer')
