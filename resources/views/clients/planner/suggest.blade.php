@include('clients.blocks.header')
<link rel="stylesheet" href="{{ asset('clients/assets/css/planner.css') }}?v=6">

{{-- /len-ke-hoach/de-xuat. Biến: $dests (tình trạng Planner-ready), $pre (điền sẵn từ ?q= hoặc từ trang tìm kiếm). Tạo xong chuyển thẳng sang trang sửa chuyến đi. --}}
<main class="tp">
    <div class="container">
        <header class="tp-head">
            <p class="tp-back"><a href="{{ route('planner') }}">← Chuyến đi của tôi</a></p>
            <h1>Để Travela đề xuất lịch trình</h1>
            <p>Chọn điểm đến, tháng đi và sở thích, Travela xếp sẵn từng ngày. Sau đó bạn vẫn thêm, bớt và sắp lại thoải mái.</p>
        </header>

        @if ($errors->any())
            <div class="tp-alert" role="alert">
                <ul>@foreach ($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul>
            </div>
        @endif

        <section class="tp-card" aria-labelledby="tpAutoH">
            <h2 id="tpAutoH">Để Travela đề xuất lịch trình</h2>
            <p class="tp-muted">Lịch trình chỉ gồm điểm đến, địa điểm, món ăn, lễ hội và trải nghiệm đã được biên tập duyệt. Giá chỉ tính khoản đã có dữ liệu.</p>
            <form method="GET" action="{{ route('planner.suggest') }}" class="tp-form tp-inline" role="search">
                <label>Hoặc mô tả chuyến đi bằng một câu
                    <input type="text" name="q" maxlength="200" value="{{ request('q') }}" placeholder="Ví dụ: Hà Nội 3 ngày văn hóa, không trekking">
                </label>
                <button type="submit" class="tp-btn tp-btn--ghost">Điền sẵn giúp tôi</button>
            </form>
            <form method="POST" action="{{ route('planner.generate') }}" class="tp-form">
                @csrf
                <label>Điểm đến
                    <select class="no-nice" name="destinationId" required>
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

        <p class="tp-muted tp-small">Muốn tự chọn từng địa điểm? <a href="{{ route('planner') }}">Tạo chuyến đi trống</a> rồi tìm và thêm theo tên.</p>
    </div>
</main>

@include('clients.blocks.footer')
