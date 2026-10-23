{{-- D4: khối Planner ngay dưới hero. Cần: $month, $plannerDestinations. Chưa có Planner thì xem trước bằng /api/v1/planner-preview. --}}
@php
    $plannerBase = config('postcards.planner_url');
    $action = $plannerBase ? url($plannerBase) : route('itineraries.index');
@endphp
<section class="hpl" id="hpl" aria-labelledby="hpl-h" data-api="{{ url('/api/v1/planner-preview') }}"
    data-has-planner="{{ $plannerBase ? 1 : 0 }}">
    <div class="container">
        <h2 id="hpl-h" class="hpl-title">Điền một câu, xem chuyến đi của bạn</h2>
        <form class="hpl-form" method="GET" action="{{ $action }}">
            <label for="hpl-dest">Tôi muốn đi</label>
            <select id="hpl-dest" name="diem-den">
                <option value="">chưa biết đâu</option>
                @foreach ($plannerDestinations as $d)
                    <option value="{{ $d['slug'] }}">{{ $d['name'] }}</option>
                @endforeach
            </select>
            <label for="hpl-days">trong</label>
            <select id="hpl-days" name="so-ngay">
                @foreach (range(1, 7) as $n)
                    <option value="{{ $n }}" @selected($n === 3)>{{ $n }} ngày</option>
                @endforeach
            </select>
            <label for="hpl-month">vào</label>
            <select id="hpl-month" name="thang">
                @foreach (range(1, 12) as $m)
                    <option value="{{ $m }}" @selected($m === $month)>tháng {{ $m }}</option>
                @endforeach
            </select>
            <button type="submit" class="hpl-go">{{ $plannerBase ? 'Lên kế hoạch' : 'Xem gợi ý' }}</button>
        </form>
        <div class="hpl-out" id="hpl-out" role="status" aria-live="polite" hidden></div>
    </div>
</section>
