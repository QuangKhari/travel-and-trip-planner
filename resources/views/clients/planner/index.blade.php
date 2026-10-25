@include('clients.blocks.header')
<link rel="stylesheet" href="{{ asset('clients/assets/css/planner.css') }}?v=1">

{{-- /len-ke-hoach. Biến: $trips (chuyến của tôi), $tray (khay chuyến đi), $startDefault (từ ?thang=N) --}}
<main class="tp">
    <div class="container">
        <header class="tp-head">
            <h1>Lên kế hoạch chuyến đi</h1>
            <p>Xếp điểm đến, món ăn, lễ hội và tour vào từng ngày. Xem quãng đường, chi phí dự kiến và thời điểm đi.</p>
        </header>

        @if ($errors->any())
            <div class="tp-alert" role="alert">
                <ul>@foreach ($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul>
            </div>
        @endif

        <div class="tp-grid">
            <section class="tp-card" aria-labelledby="tpNewH">
                <h2 id="tpNewH">Tạo chuyến đi mới</h2>
                <form method="POST" action="{{ route('planner.store') }}" class="tp-form">
                    @csrf
                    <label>Tên chuyến đi
                        <input type="text" name="title" value="{{ old('title') }}" maxlength="160" required placeholder="Ví dụ: Đà Nẵng 4 ngày">
                    </label>
                    <div class="tp-row">
                        <label>Ngày đi (không bắt buộc)
                            <input type="date" name="startDate" value="{{ old('startDate', $startDefault) }}">
                        </label>
                        <label>Số ngày
                            <input type="number" name="days" min="1" max="{{ \App\Services\Planner\TripPlanner::MAX_DAYS }}" value="{{ old('days', 3) }}" required>
                        </label>
                    </div>
                    <div class="tp-row">
                        <label>Số người
                            <input type="number" name="groupSize" min="1" max="50" value="{{ old('groupSize', 2) }}" required>
                        </label>
                        <label>Ngân sách (đ, không bắt buộc)
                            <input type="number" name="budget" min="0" step="1000" value="{{ old('budget') }}">
                        </label>
                    </div>
                    @if (count($tray))
                        <label class="tp-check">
                            <input type="checkbox" name="fromTray" value="1" checked>
                            Dùng {{ count($tray) }} mục trong khay chuyến đi (tự chia vào các ngày)
                        </label>
                        <ul class="tp-tray">
                            @foreach (array_slice($tray, 0, 8) as $t)<li>{{ $t['name'] }}</li>@endforeach
                            @if (count($tray) > 8)<li>… và {{ count($tray) - 8 }} mục khác</li>@endif
                        </ul>
                    @else
                        <p class="tp-muted">Khay chuyến đi đang trống. Bấm “Thêm vào chuyến đi” ở điểm đến, món ăn hay lễ hội, rồi quay lại đây, hoặc tạo chuyến trống và thêm gợi ý trong trang sửa.</p>
                    @endif
                    <button type="submit" class="tp-btn">Tạo chuyến đi</button>
                </form>
            </section>

            <section class="tp-card" aria-labelledby="tpListH">
                <h2 id="tpListH">Chuyến đi của tôi</h2>
                @forelse ($trips as $t)
                    <article class="tp-trip">
                        <a href="{{ route('planner.edit', ['id' => $t->tripId]) }}">
                            <b>{{ $t->title }}</b>
                            <small>
                                {{ $t->days }} ngày · {{ $t->itemCount }} mục
                                @if ($t->startDate) · từ {{ \Carbon\Carbon::parse($t->startDate)->format('d/m/Y') }}@endif
                            </small>
                        </a>
                        <form method="POST" action="{{ route('planner.destroy', ['id' => $t->tripId]) }}"
                            onsubmit="return confirm('Xóa chuyến đi này?');">
                            @csrf
                            <button type="submit" class="tp-link tp-link--danger">Xóa</button>
                        </form>
                    </article>
                @empty
                    <p class="tp-muted">Bạn chưa có chuyến đi nào.</p>
                @endforelse
            </section>
        </div>
    </div>
</main>

@include('clients.blocks.footer')
