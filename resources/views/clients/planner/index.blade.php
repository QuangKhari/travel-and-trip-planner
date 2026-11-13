@include('clients.blocks.header')
<link rel="stylesheet" href="{{ asset('clients/assets/css/planner.css') }}?v=11">
<link rel="stylesheet" href="{{ asset('clients/assets/css/planner-ai-chat.css') }}?v=4">
<link rel="stylesheet" href="{{ asset('clients/assets/css/nature-luxury-planner.css') }}?v=bl20261104">
<link rel="stylesheet" href="{{ asset('clients/assets/css/travela-nature-all-pages.css') }}?v=bl20261104">

{{-- /len-ke-hoach. Biến: $trips, $sharedTrips, $tray, $allDests.
     "Chuyến đi của tôi" dạng thẻ có ảnh (tham khảo Wanderlog / Tripora) + tạo chuyến 3 bước: Đi đâu? Khi nào? Đi cùng ai?
     Biểu mẫu vẫn gửi đủ trường khi tắt JavaScript (các bước hiện cùng lúc); planner-list.js biến nó thành từng bước. --}}
@php
    $phaseLabel = [
        'planning' => 'Đang lên kế hoạch',
        'upcoming' => 'Sắp đi',
        'ongoing' => 'Đang đi',
        'past' => 'Đã qua ngày đi',
        'done' => 'Đã đi',
    ];
    $statusLabel = ['draft' => 'Nháp', 'planned' => 'Đã lên kế hoạch', 'done' => 'Đã đi'];
    $count = ['all' => count($trips), 'shared' => count($sharedTrips ?? [])];
    foreach ($trips as $t) {
        $count[$t->phase] = ($count[$t->phase] ?? 0) + 1;
    }
    $fmt = fn($d) => $d ? \Carbon\Carbon::parse($d)->format('d/m') : null;
@endphp

<main class="tp tpl">
    <div class="tpl-wrap">
        <header class="tpl-head">
            <div>
                <h1>Chuyến đi của tôi</h1>
                <p>Lên lịch theo ngày, xem tuyến trên bản đồ, quãng đường và chi phí tự cập nhật.</p>
            </div>
            <div class="tpl-head__act">
                <a class="tp-btn tp-btn--ghost" href="{{ route('planner.discover') }}"><i class="fas fa-compass"
                        aria-hidden="true"></i> Chưa biết đi đâu?</a>
                <a class="tp-btn tp-btn--ghost" href="{{ route('planner.suggest') }}"><i class="fas fa-magic"
                        aria-hidden="true"></i> Để Travela đề xuất</a>
                <a class="tp-btn" href="#tplNew" id="tplNewBtn"><i class="fas fa-plus" aria-hidden="true"></i> Chuyến đi
                    mới</a>
            </div>
        </header>

        @if (session('planner_notice'))
            <p class="tpx-notice" role="status">{{ session('planner_notice') }}</p>
        @endif
        @if ($errors->any())
            <div class="tp-alert" role="alert">
                <ul>
                    @foreach ($errors->all() as $e)
                        <li>{{ $e }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        {{-- ================= Chọn cách tạo chuyến ================= --}}
        <div class="pch" role="group" aria-label="Chọn cách tạo chuyến đi">
            <a class="pch-c" href="#tplNew" id="pchManual">
                <h2>Tự tạo chuyến đi</h2>
                <p>Bạn nắm quyền: chọn điểm đến, ngày đi rồi tự xếp từng nơi vào lịch.</p>
                <span class="go">Tạo thủ công</span>
            </a>
            <a class="pch-c ai" href="{{ route('planner.ai.chat') }}">
                <span class="pch-tag">MỚI</span>
                <h2>Để AI tạo giúp</h2>
                <p>Trò chuyện vài câu, AI hỏi những gì cần biết rồi xếp sẵn lịch trình cho bạn.</p>
                <span class="go">Trò chuyện với Travela AI</span>
            </a>
        </div>
        <script>
            document.getElementById('pchManual').addEventListener('click', function(e) {
                var s = document.getElementById('tplNew');
                s.removeAttribute('data-collapsed');
                e.preventDefault();
                s.scrollIntoView({
                    behavior: 'smooth'
                });
            });
        </script>

        {{-- ================= Tạo chuyến đi mới (3 bước) ================= --}}
        <section class="tpl-new" id="tplNew" aria-labelledby="tplNewH"
            @if (count($trips) && !$errors->any()) data-collapsed="1" @endif>
            <div class="tpl-new__head">
                <h2 id="tplNewH">Tạo chuyến đi mới</h2>
                <span class="tpl-step" id="tplStepLbl" aria-live="polite"></span>
            </div>
            <div class="tpl-prog" aria-hidden="true"><span></span><span></span><span></span></div>
            <form method="POST" action="{{ route('planner.store') }}" class="tpl-form" id="tplForm" novalidate>
                @csrf
                {{-- Bước 1 --}}
                <fieldset class="tpl-fs" data-step="1">
                    <legend><span>1</span> Đi đâu?</legend>
                    <label class="tpl-field tpl-field--big">
                        <i class="fas fa-map-marker-alt" aria-hidden="true"></i>
                        <input type="text" id="tplWhere" list="tplDests" autocomplete="off"
                            placeholder="Ví dụ: Đà Nẵng, Hội An, Phong Nha" aria-label="Điểm đến chính"
                            value="{{ old('where') }}">
                    </label>
                    <datalist id="tplDests">
                        @foreach ($allDests as $d)
                            <option value="{{ $d->name }}" data-id="{{ $d->destinationId }}"></option>
                        @endforeach
                    </datalist>
                    <select class="no-nice tpl-nojs" name="destinationId" id="tplDest"
                        aria-label="Chọn điểm đến chính (không bắt buộc)">
                        <option value="">Chưa chọn, tìm ở mọi nơi</option>
                        @foreach ($allDests as $d)
                            <option value="{{ $d->destinationId }}" @selected((int) old('destinationId') === (int) $d->destinationId)>{{ $d->name }}
                            </option>
                        @endforeach
                    </select>
                    <p class="tp-muted tp-small" id="tplWhereMsg">Không bắt buộc. Chọn một điểm đến để Travela ưu tiên
                        nội dung ở đó; nơi chưa có trên Travela vẫn lập được, chỉ dùng làm tên chuyến.</p>
                    <div class="tpl-chipsrow" id="tplPopular">
                        @foreach ($popularDests ?? $allDests->take(8) as $d)
                            <button type="button" class="tp-chip"
                                data-dest="{{ $d->destinationId }}">{{ $d->name }}</button>
                        @endforeach
                    </div>
                </fieldset>
                {{-- Bước 2 --}}
                <fieldset class="tpl-fs" data-step="2">
                    <legend><span>2</span> Khi nào?</legend>
                    <div class="tpl-row">
                        <label class="tpl-field"><span>Ngày đi</span><input type="date" name="startDate"
                                id="tplStart" value="{{ old('startDate') }}"
                                min="{{ now()->format('Y-m-d') }}"></label>
                        <label class="tpl-field"><span>Ngày về</span><input type="date" id="tplEnd"
                                value="{{ old('endDate') }}"></label>
                        <label class="tpl-field tpl-field--sm"><span>Số ngày</span><input type="number" name="days"
                                id="tplDays" min="1" max="{{ \App\Services\Planner\TripPlanner::MAX_DAYS }}"
                                value="{{ old('days', 3) }}" required></label>
                    </div>
                    <p class="tp-muted tp-small" id="tplWhenMsg">Chưa chắc ngày? Để trống ngày đi và chỉ chọn số ngày,
                        có thể thêm sau. Tối đa {{ \App\Services\Planner\TripPlanner::MAX_DAYS }} ngày.</p>
                </fieldset>
                {{-- Bước 3 --}}
                <fieldset class="tpl-fs" data-step="3">
                    <legend><span>3</span> Đi cùng ai?</legend>
                    <div class="tpl-row">
                        <div class="tpl-field tpl-field--sm">
                            <span id="tplGroupL">Số người</span>
                            <div class="tpl-stepper">
                                <button type="button" data-d="-1" aria-label="Bớt một người">−</button>
                                <input type="number" name="groupSize" id="tplGroup" min="1" max="50"
                                    value="{{ old('groupSize', 2) }}" required aria-labelledby="tplGroupL">
                                <button type="button" data-d="1" aria-label="Thêm một người">+</button>
                            </div>
                        </div>
                        <label class="tpl-field"><span>Ngân sách cả nhóm (đ, không bắt buộc)</span><input
                                type="number" name="budget" min="0" step="100000"
                                value="{{ old('budget') }}" placeholder="Có thể nhập sau"></label>
                    </div>
                    <label class="tpl-field"><span>Tên chuyến đi</span><input type="text" name="title"
                            id="tplTitle" value="{{ old('title') }}" maxlength="160" required
                            placeholder="Ví dụ: Đà Nẵng 4 ngày"></label>
                    @if (count($tray))
                        <label class="tp-check tpl-trayopt">
                            <input type="checkbox" name="fromTray" value="1" checked>
                            <span>Dùng {{ count($tray) }} nơi trong <b>khay chuyến đi</b> (tự chia vào các ngày):
                                {{ implode(', ', array_map(fn($x) => $x['name'], array_slice($tray, 0, 4))) }}
                                @if (count($tray) > 4)
                                    và {{ count($tray) - 4 }} nơi khác
                                @endif
                            </span>
                        </label>
                    @endif
                    <p class="tp-muted tp-small">Mời bạn đồng hành sau khi tạo, ở nút “Chia sẻ” của chuyến đi.</p>
                </fieldset>
                <div class="tpl-nav">
                    <button type="button" class="tp-btn tp-btn--ghost" id="tplBack" hidden>Quay lại</button>
                    <button type="button" class="tp-btn" id="tplNext" hidden>Tiếp tục</button>
                    <button type="submit" class="tp-btn" id="tplSubmit">Bắt đầu lên kế hoạch</button>
                </div>
            </form>
        </section>

        {{-- ================= Danh sách chuyến ================= --}}
        <section aria-labelledby="tplListH">
            <div class="tpl-listhead">
                <h2 id="tplListH">Chuyến đi</h2>
                <div class="tpl-filters" role="tablist" aria-label="Lọc chuyến đi">
                    @foreach (['all' => 'Tất cả', 'planning' => 'Đang lên kế hoạch', 'upcoming' => 'Sắp đi', 'ongoing' => 'Đang đi', 'done' => 'Đã đi', 'shared' => 'Được mời'] as $k => $l)
                        @if ($k === 'all' || !empty($count[$k]) || ($k === 'done' && !empty($count['past'])))
                            <button type="button" role="tab"
                                class="tpl-filter{{ $k === 'all' ? ' is-on' : '' }}" data-f="{{ $k }}"
                                aria-selected="{{ $k === 'all' ? 'true' : 'false' }}">{{ $l }}
                                <b>{{ $k === 'done' ? ($count['done'] ?? 0) + ($count['past'] ?? 0) : $count[$k] ?? 0 }}</b></button>
                        @endif
                    @endforeach
                </div>
            </div>

            @if (!count($trips) && empty($sharedTrips))
                <div class="tpx-blank">
                    <i class="fas fa-suitcase-rolling" aria-hidden="true"></i>
                    <b>Bạn chưa có chuyến đi nào</b>
                    <p class="tp-muted">Điền ba bước ở trên để bắt đầu, chỉ cần một cái tên là đủ.</p>
                </div>
            @endif

            <ul class="tpl-grid" id="tplGrid">
                @foreach ($trips as $t)
                    <li class="tpl-card"
                        data-phase="{{ in_array($t->phase, ['past', 'done'], true) ? 'done' : $t->phase }}">
                        <a class="tpl-card__ph" href="{{ route('planner.edit', ['id' => $t->tripId]) }}"
                            @if ($t->coverUrl) style="background-image: url('{{ $t->coverUrl }}')" @endif
                            aria-label="Mở {{ $t->title }}">
                            @unless ($t->coverUrl)
                                <span
                                    class="tpl-card__ini">{{ mb_strtoupper(mb_substr($t->destName ?: $t->title, 0, 1)) }}</span>
                            @endunless
                            <span
                                class="tpl-badge tpl-badge--{{ $t->phase }}">{{ $phaseLabel[$t->phase] ?? '' }}</span>
                            @if ($t->daysLeft !== null && $t->daysLeft <= 60)
                                <span class="tpl-badge tpl-badge--left">Còn {{ $t->daysLeft }} ngày</span>
                            @endif
                        </a>
                        <div class="tpl-card__b">
                            <a class="tpl-card__t"
                                href="{{ route('planner.edit', ['id' => $t->tripId]) }}">{{ $t->title }}</a>
                            <p class="tpl-card__m">
                                @if ($t->destName)
                                    <span><i class="fas fa-map-marker-alt" aria-hidden="true"></i>
                                        {{ $t->destName }}</span>
                                @endif
                                <span><i class="fas fa-calendar-alt" aria-hidden="true"></i>
                                    {{ $t->startDate ? $fmt($t->startDate) . ($t->days > 1 ? ' – ' . $fmt($t->endDate) : '') : 'Chưa chọn ngày' }}</span>
                            </p>
                            <p class="tpl-card__s">{{ $t->days }} ngày ·
                                {{ (int) $t->placedCount }}/{{ (int) $t->itemCount }} mục đã xếp ·
                                {{ $t->groupSize }} người
                                @if ((int) $t->memberCount)
                                    <span class="tpl-badge tpl-badge--shared"><i class="fas fa-user-friends"
                                            aria-hidden="true"></i> {{ (int) $t->memberCount }} bạn đồng hành</span>
                                @endif
                            </p>
                            <div class="tpl-card__act">
                                <form method="POST" action="{{ route('planner.status', ['id' => $t->tripId]) }}">
                                    @csrf
                                    <select class="no-nice" name="status" onchange="this.form.submit()"
                                        aria-label="Trạng thái của {{ $t->title }}">
                                        @foreach ($statusLabel as $v => $l)
                                            <option value="{{ $v }}" @selected($t->status === $v)>
                                                {{ $l }}</option>
                                        @endforeach
                                    </select>
                                    <noscript><button type="submit" class="tp-link">Lưu</button></noscript>
                                </form>
                                <form method="POST" action="{{ route('planner.duplicate', ['id' => $t->tripId]) }}">
                                    @csrf
                                    <button type="submit" class="tpl-ibtn" title="Nhân bản thành chuyến mới"
                                        aria-label="Nhân bản {{ $t->title }}"><i class="far fa-copy"
                                            aria-hidden="true"></i></button>
                                </form>
                                <form method="POST" action="{{ route('planner.destroy', ['id' => $t->tripId]) }}"
                                    onsubmit="return confirm('Xóa chuyến đi này?');">
                                    @csrf
                                    <button type="submit" class="tpl-ibtn tpl-ibtn--danger" title="Xóa chuyến đi"
                                        aria-label="Xóa {{ $t->title }}"><i class="fas fa-trash-alt"
                                            aria-hidden="true"></i></button>
                                </form>
                            </div>
                        </div>
                    </li>
                @endforeach
                @foreach ($sharedTrips ?? [] as $t)
                    <li class="tpl-card tpl-card--shared" data-phase="shared">
                        <a class="tpl-card__ph" href="{{ route('planner.edit', ['id' => $t->tripId]) }}"
                            @if ($t->coverUrl) style="background-image: url('{{ $t->coverUrl }}')" @endif
                            aria-label="Mở {{ $t->title }}">
                            @unless ($t->coverUrl)
                                <span
                                    class="tpl-card__ini">{{ mb_strtoupper(mb_substr($t->destName ?: $t->title, 0, 1)) }}</span>
                            @endunless
                            <span
                                class="tpl-badge tpl-badge--shared">{{ $t->myRole === 'edit' ? 'Được chỉnh sửa' : 'Chỉ xem' }}</span>
                        </a>
                        <div class="tpl-card__b">
                            <a class="tpl-card__t"
                                href="{{ route('planner.edit', ['id' => $t->tripId]) }}">{{ $t->title }}</a>
                            <p class="tpl-card__m"><span><i class="fas fa-user" aria-hidden="true"></i> Được mời vào
                                    chuyến của {{ $t->ownerName }}</span>
                                <span><i class="fas fa-calendar-alt" aria-hidden="true"></i>
                                    {{ $t->startDate ? $fmt($t->startDate) . ($t->days > 1 ? ' – ' . $fmt($t->endDate) : '') : 'Chưa chọn ngày' }}</span>
                            </p>
                            <p class="tpl-card__s">{{ $t->days }} ngày · {{ (int) $t->itemCount }} mục</p>
                            <div class="tpl-card__act">
                                <form method="POST" action="{{ route('planner.duplicate', ['id' => $t->tripId]) }}">
                                    @csrf
                                    <button type="submit" class="tpl-ibtn" title="Nhân bản thành chuyến của bạn"
                                        aria-label="Nhân bản {{ $t->title }}"><i class="far fa-copy"
                                            aria-hidden="true"></i></button>
                                </form>
                                <form method="POST"
                                    action="{{ route('planner.memberRemove', ['id' => $t->tripId, 'userId' => session('userId')]) }}"
                                    onsubmit="return confirm('Rời khỏi chuyến đi này?');">
                                    @csrf
                                    <button type="submit" class="tp-link tp-link--danger">Rời chuyến</button>
                                </form>
                            </div>
                        </div>
                    </li>
                @endforeach
            </ul>
            <p class="tp-muted tpl-none" id="tplNone" hidden>Không có chuyến nào trong mục này.</p>
        </section>

        <aside class="tpl-tray" aria-labelledby="tplTrayH">
            <i class="fas fa-shopping-basket" aria-hidden="true"></i>
            <div>
                <h2 id="tplTrayH">Khay chuyến đi{{ count($tray) ? ' · ' . count($tray) . ' nơi' : '' }}</h2>
                <p class="tp-muted tp-small">Khay là chỗ lưu tạm những nơi bạn bấm “Thêm vào chuyến đi” khi xem điểm
                    đến, món ăn, lễ hội. Nó chưa phải chuyến đi: tạo chuyến mới ở trên và chọn “Dùng các nơi trong khay”
                    để chia chúng vào từng ngày.</p>
            </div>
        </aside>
    </div>
</main>

<script src="{{ asset('clients/assets/js/planner-list.js') }}?v=1" defer></script>
@include('clients.blocks.footer')
