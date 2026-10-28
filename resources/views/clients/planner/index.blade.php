@include('clients.blocks.header')
<link rel="stylesheet" href="{{ asset('clients/assets/css/planner.css') }}?v=1">

{{-- /len-ke-hoach. Biến: $trips, $tray, $startDefault (từ ?thang=N), $dests (tình trạng Planner-ready), $pre (điền sẵn từ ?q= hoặc trang tìm kiếm) --}}
<main class="tp">
    <div class="container">
        <header class="tp-head">
            <h1>Lên kế hoạch chuyến đi</h1>
            <p>Xếp điểm đến, món ăn, lễ hội và tour vào từng ngày. Xem quãng đường, chi phí dự kiến và thời điểm đi.</p>
        </header>

        @if (session('planner_notice'))<p class="tp-card" role="status">{{ session('planner_notice') }}</p>@endif
        @if ($errors->any())
            <div class="tp-alert" role="alert">
                <ul>@foreach ($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul>
            </div>
        @endif

        <section class="tp-card" aria-labelledby="tpAutoH">
            <h2 id="tpAutoH">Để Travela đề xuất lịch trình</h2>
            <p class="tp-muted">Lịch trình chỉ gồm điểm đến, địa điểm, món ăn, lễ hội và trải nghiệm đã được biên tập duyệt. Giá chỉ tính khoản đã có dữ liệu.</p>
            <form method="GET" action="{{ route('planner') }}" class="tp-form" role="search">
                <label>Hoặc mô tả chuyến đi bằng một câu
                    <input type="text" name="q" maxlength="200" value="{{ request('q') }}" placeholder="Ví dụ: Hà Nội 3 ngày văn hóa, không trekking">
                </label>
                <button type="submit" class="tp-link">Điền sẵn giúp tôi</button>
            </form>
            <form method="POST" action="{{ route('planner.generate') }}" class="tp-form">
                @csrf
                <label>Điểm đến
                    <select name="destinationId" required>
                        <option value="">— Chọn điểm đến —</option>
                        @foreach ($dests as $d)
                            <option value="{{ $d['destinationId'] }}" @selected((int) old('destinationId') === $d['destinationId'] || (!old('destinationId') && $pre['destination'] === $d['slug'])) @disabled(!$d['ready'])>
                                {{ $d['name'] }}{{ $d['ready'] ? '' : ' (chưa đủ dữ liệu)' }}
                            </option>
                        @endforeach
                    </select>
                </label>
                <div class="tp-row">
                    <label>Tháng đi
                        <input type="number" name="month" min="1" max="12" value="{{ old('month', $pre['month']) }}" required>
                    </label>
                    <label>Số ngày
                        <input type="number" name="days" min="1" max="{{ (int) config('planner.max_days', 7) }}" value="{{ old('days', $pre['days'] ?? 3) }}" required>
                    </label>
                </div>
                <div class="tp-row">
                    <label>Số người
                        <input type="number" name="groupSize" min="1" max="50" value="{{ old('groupSize', $pre['groupSize'] ?? 2) }}" required>
                    </label>
                    <label>Ngân sách (đ, không bắt buộc)
                        <input type="number" name="budget" min="0" step="1000" value="{{ old('budget', $pre['budget']) }}">
                    </label>
                </div>
                <fieldset>
                    <legend>Sở thích</legend>
                    @foreach (config('interests') as $key => $def)
                        <label class="tp-check"><input type="checkbox" name="interests[]" value="{{ $key }}" @checked(in_array($key, (array) old('interests', $pre['interests']), true))> {{ $def['label'] }}</label>
                    @endforeach
                </fieldset>
                <fieldset>
                    <legend>Không thích</legend>
                    @foreach (['trekking' => 'Trekking, leo núi', 'crowds' => 'Nơi đông đúc', 'outdoor' => 'Hoạt động ngoài trời', 'long_travel' => 'Di chuyển xa'] as $key => $label)
                        @if (array_key_exists($key, (array) config('planner.dislikes')))
                            <label class="tp-check"><input type="checkbox" name="dislikes[]" value="{{ $key }}" @checked(in_array($key, (array) old('dislikes', $pre['dislikes']), true))> {{ $label }}</label>
                        @endif
                    @endforeach
                </fieldset>
                <button type="submit" class="tp-btn">Tạo lịch trình</button>
            </form>
        </section>

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
                        <form method="POST" action="{{ route('planner.status', ['id' => $t->tripId]) }}">
                            @csrf
                            <label class="tp-muted">Trạng thái
                                <select name="status" onchange="this.form.submit()">
                                    @foreach (['draft' => 'Nháp', 'planned' => 'Đã lên kế hoạch', 'done' => 'Đã đi'] as $v => $l)
                                        <option value="{{ $v }}" @selected($t->status === $v)>{{ $l }}</option>
                                    @endforeach
                                </select>
                            </label>
                            <noscript><button type="submit" class="tp-link">Lưu</button></noscript>
                        </form>
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
