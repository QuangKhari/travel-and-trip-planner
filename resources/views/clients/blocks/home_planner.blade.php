{{-- D4: khối Planner ngay dưới hero. Cần: $month, $plannerDestinations. Chưa có Planner thì xem trước bằng /api/v1/planner-preview.
     Ba ô đọc liền thành một câu: "Tôi muốn đi … trong … vào …". Select dùng class no-nice để plugin nice-select
     (script.js) không bọc lại làm vỡ bố cục như trước. --}}
@php
    $plannerBase = config('postcards.planner_url');
    $action = $plannerBase ? url($plannerBase) : route('itineraries.index');
@endphp
<section class="hpl" id="hpl" aria-labelledby="hpl-h" data-api="{{ url('/api/v1/planner-preview') }}"
    data-has-planner="{{ $plannerBase ? 1 : 0 }}">
    <div class="container">
        <div class="hpl-head">
            <p class="hs-eyebrow">Biến cảm hứng thành chuyến đi</p>
            <h2 id="hpl-h" class="hpl-title">Điền một câu, xem chuyến đi của bạn</h2>
            <p class="hpl-sub">Chọn ba ô bên dưới. Không cần đăng nhập, gợi ý hiện ngay.</p>
        </div>

        <form class="hpl-form" method="GET" action="{{ $action }}">
            <div class="hpl-field">
                <span class="hpl-ic" aria-hidden="true"><i class="fas fa-map-marker-alt"></i></span>
                <div class="hpl-box">
                    <label for="hpl-dest">Tôi muốn đi</label>
                    <select id="hpl-dest" name="diem-den" class="no-nice">
                        <option value="">Chưa biết đâu, gợi ý giúp tôi</option>
                        @foreach ($plannerDestinations as $d)
                            <option value="{{ $d['slug'] }}">{{ $d['name'] }}</option>
                        @endforeach
                    </select>
                </div>
            </div>

            <div class="hpl-field">
                <span class="hpl-ic" aria-hidden="true"><i class="fas fa-hourglass-half"></i></span>
                <div class="hpl-box">
                    <label for="hpl-days">trong</label>
                    <select id="hpl-days" name="so-ngay" class="no-nice">
                        @foreach (range(1, 7) as $n)
                            <option value="{{ $n }}" @selected($n === 3)>{{ $n }} ngày
                            </option>
                        @endforeach
                    </select>
                </div>
            </div>

            <div class="hpl-field">
                <span class="hpl-ic" aria-hidden="true"><i class="fas fa-calendar-alt"></i></span>
                <div class="hpl-box">
                    <label for="hpl-month">vào</label>
                    <select id="hpl-month" name="thang" class="no-nice">
                        @foreach (range(1, 12) as $m)
                            <option value="{{ $m }}" @selected($m === $month)>Tháng {{ $m }}
                            </option>
                        @endforeach
                    </select>
                </div>
            </div>

            <button type="submit" class="hpl-go">
                <span>{{ $plannerBase ? 'Lên kế hoạch' : 'Xem gợi ý' }}</span>
                <i class="fas fa-arrow-right" aria-hidden="true"></i>
            </button>
        </form>
        <div class="hpl-out" id="hpl-out" role="status" aria-live="polite" hidden></div>
    </div>
</section>
