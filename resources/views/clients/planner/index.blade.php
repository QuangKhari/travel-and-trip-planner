@include('clients.blocks.header')
<link rel="stylesheet" href="{{ asset('clients/assets/css/planner.css') }}?v=6">

{{-- /len-ke-hoach. Biến: $trips, $tray, $allDests. Chỉ phần TỰ lên kế hoạch; phần Travela đề xuất ở /len-ke-hoach/de-xuat. --}}
<main class="tp">
    <div class="container">
        <header class="tp-head">
            <h1>Lên kế hoạch chuyến đi</h1>
            <p>Tạo chuyến đi, tìm địa điểm theo tên rồi xếp vào từng ngày. Quãng đường và chi phí tự cập nhật.</p>
        </header>

        @if (session('planner_notice'))<p class="tp-card" role="status">{{ session('planner_notice') }}</p>@endif
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
                    <label>Điểm đến chính (không bắt buộc)
                        <select class="no-nice" name="destinationId">
                            <option value="">Chưa chọn, tìm ở mọi nơi</option>
                            @foreach ($allDests as $d)<option value="{{ $d->destinationId }}" @selected((int) old('destinationId') === (int) $d->destinationId)>{{ $d->name }}</option>@endforeach
                        </select>
                    </label>
                    <div class="tp-row">
                        <label>Ngày đi (không bắt buộc)
                            <input type="date" name="startDate" value="{{ old('startDate') }}">
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
                            <input type="number" name="budget" min="0" step="1000" value="{{ old('budget') }}" placeholder="Có thể nhập sau">
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
                    <button type="submit" class="tp-btn">Bắt đầu lên kế hoạch</button>
                </form>
            </section>

            <section class="tp-card" aria-labelledby="tpListH">
                <h2 id="tpListH">Chuyến đi của tôi</h2>
                @forelse ($trips as $t)
                    <article class="tp-trip">
                        <a href="{{ route('planner.edit', ['id' => $t->tripId]) }}">
                            <b>{{ $t->title }}</b>
                            <span class="tp-status tp-status--{{ $t->status }}">{{ ['draft' => 'Nháp', 'planned' => 'Đã lên kế hoạch', 'done' => 'Đã đi'][$t->status] ?? 'Nháp' }}</span>
                            <small>
                                {{ $t->days }} ngày · {{ $t->itemCount }} mục
                                @if ($t->startDate) · từ {{ \Carbon\Carbon::parse($t->startDate)->format('d/m/Y') }}@endif
                            </small>
                        </a>
                        <div class="tp-trip__act">
                            <form method="POST" action="{{ route('planner.status', ['id' => $t->tripId]) }}">
                                @csrf
                                <label class="tp-muted">Trạng thái
                                    <select class="no-nice" name="status" onchange="this.form.submit()">
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
                        </div>
                    </article>
                @empty
                    <p class="tp-empty-note">Bạn chưa có chuyến đi nào. Điền form bên cạnh để bắt đầu, chỉ cần một cái tên là đủ.</p>
                @endforelse
            </section>
        </div>

        <aside class="tp-card tp-cta" aria-labelledby="tpCtaH">
            <div>
                <h2 id="tpCtaH">Chưa biết nên đi đâu, làm gì?</h2>
                <p class="tp-muted">Để Travela xếp sẵn lịch trình từ nội dung đã được biên tập duyệt, rồi bạn chỉnh lại theo ý mình.</p>
            </div>
            <a class="tp-btn tp-btn--ghost" href="{{ route('planner.suggest') }}">Để Travela đề xuất</a>
        </aside>
    </div>
</main>

@include('clients.blocks.footer')
