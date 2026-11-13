@include('clients.blocks.header')
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css">
<link rel="stylesheet" href="{{ asset('clients/assets/css/planner.css') }}?v=26">
<link rel="stylesheet" href="{{ asset('clients/assets/css/planner-ai.css') }}?v=6">
<link rel="stylesheet" href="{{ asset('clients/assets/css/planner-ux.css') }}?v=1">
<link rel="stylesheet" href="{{ asset('clients/assets/css/nature-luxury-planner.css') }}?v=bl20261104">
<link rel="stylesheet" href="{{ asset('clients/assets/css/travela-nature-all-pages.css') }}?v=bl20261104">

{{-- /len-ke-hoach/{id}. Biến: $payload (TripPlanner::payload), $client (dữ liệu cho planner.js), $role, $members, $tourFit.
     Bố cục "Timeline + Bản đồ" (tham khảo Wanderlog: lịch theo ngày cạnh bản đồ; Roadtrippers: bản đồ lớn, tuyến nối các điểm, thêm điểm dừng theo danh mục).
     Toàn bộ dải ngày, timeline, sức khỏe chuyến đi, chi phí và tổng quan do planner.js vẽ từ #tpData; Blade chỉ dựng khung. --}}
@php
    $t = $payload['trip'];
@endphp

<a href="#tpPanelPlan" class="tpux-skip">Bỏ qua thanh công cụ, đến lịch trình</a>
<main class="tp tp--edit tpx tpux" id="tpRoot">
    {{-- ---------------- Thanh công cụ trên cùng (kiểu Wanderlog: đã lưu · hoàn tác · làm lại ... chia sẻ) ---------------- --}}
    <div class="tpx-top" role="toolbar" aria-label="Công cụ chuyến đi">
        <a class="tpx-back" href="{{ route('planner') }}"><i class="fas fa-arrow-left" aria-hidden="true"></i> Chuyến đi của tôi</a>
        <div class="tpx-head__act">
            <span id="tpMsg" class="tpx-save" role="status" aria-live="polite"></span>
            @if ($aiCopilotOn)
                <button type="button" class="ai-co-open" id="aiCoOpen" aria-controls="aiCo" aria-expanded="false"><span class="ai-mark ai-mark--sm" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none"><circle cx="12" cy="12" r="9.2" stroke="currentColor" stroke-width="1.6"/><path d="M12 4.5 14.2 12 12 19.5 9.8 12Z" fill="#e9a23b"/><path d="M12 12 14.2 12 12 19.5Z" fill="#167A85"/></svg></span>Hỏi La bàn</button>
            @endif
            @if ($role !== 'view')
                <span class="tpx-iconbtns">
                    <button type="button" id="tpUndo" class="tpx-icon" disabled title="Hoàn tác (Ctrl+Z)" aria-label="Hoàn tác"><i class="fas fa-undo" aria-hidden="true"></i></button>
                    <button type="button" id="tpRedo" class="tpx-icon" disabled title="Làm lại (Ctrl+Shift+Z)" aria-label="Làm lại"><i class="fas fa-redo" aria-hidden="true"></i></button>
                </span>
                <button type="button" id="tpSave" class="tp-mini tpx-savenow" title="Lưu thay đổi ngay (Ctrl+S)" hidden>Lưu ngay</button>
            @else
                <span class="tp-role tp-role--view">Chỉ xem</span>
                <button type="button" id="tpUndo" hidden disabled></button><button type="button" id="tpRedo" hidden disabled></button>
                <button type="button" id="tpSave" hidden>Lưu thay đổi</button>
            @endif
            {{-- Thao tác ít dùng gom vào một menu: thanh công cụ chỉ còn trạng thái lưu, hoàn tác/làm lại và Chia sẻ --}}
            <details class="tpx-menu tpx-topmenu" id="tpTopMenu">
                <summary class="tpx-icon tpx-icon--solo" title="Thêm thao tác" aria-label="Thêm thao tác"><i class="fas fa-ellipsis-v" aria-hidden="true"></i></summary>
                <div class="tpx-menu__box" role="menu">
                    @if ($role !== 'view')
                        <button type="button" class="tpx-menu__i" role="menuitem" data-top="optimize"><i class="fas fa-route" aria-hidden="true"></i><span>Tối ưu tuyến ngày đang xem</span></button>
                        <button type="button" class="tpx-menu__i" role="menuitem" data-top="balance"><i class="fas fa-balance-scale" aria-hidden="true"></i><span>Tự cân bằng ngày đang xem</span></button>
                    @endif
                    <button type="button" class="tpx-menu__i" role="menuitem" data-top="health"><i class="fas fa-heartbeat" aria-hidden="true"></i><span>Kiểm tra sức khỏe chuyến đi</span></button>
                    <button type="button" class="tpx-menu__i" role="menuitem" data-top="cost"><i class="fas fa-wallet" aria-hidden="true"></i><span>Ngân sách</span></button>
                    @if (!empty($client['historyUrl']))
                        <button type="button" class="tpx-menu__i" role="menuitem" data-top="history" id="tpHistOpen" aria-haspopup="dialog" aria-controls="tpHist"><i class="fas fa-history" aria-hidden="true"></i><span>Lịch sử phiên bản</span></button>
                    @endif
                    <a class="tpx-menu__i" role="menuitem" href="{{ route('support.index', ['trip' => $t['id']]) }}#tao-yeu-cau"><i class="fas fa-headset" aria-hidden="true"></i><span>Nhờ nhân viên tư vấn chuyến này</span></a>
                    <button type="button" class="tpx-menu__i" role="menuitem" id="tpPdf" data-url="{{ route('planner.pdf', ['id' => $t['id']]) }}" data-lib="{{ asset('admin/vendors/pdfmake/build') }}"><i class="fas fa-file-pdf" aria-hidden="true"></i><span>Tải PDF lịch trình và đơn tour</span></button>
                    <button type="button" class="tpx-menu__i" role="menuitem" id="tpPrint"><i class="fas fa-print" aria-hidden="true"></i><span>In trang</span></button>
                    <form method="POST" action="{{ route('planner.duplicate', ['id' => $t['id']]) }}" id="tpDupForm">
                        @csrf
                        <button type="submit" class="tpx-menu__i" role="menuitem"><i class="far fa-copy" aria-hidden="true"></i><span>Nhân bản thành chuyến mới</span></button>
                    </form>
                    @if ($role === 'owner')
                        <form method="POST" action="{{ route('planner.destroy', ['id' => $t['id']]) }}" onsubmit="return confirm('Xóa hẳn chuyến đi này? Không hoàn tác được.');">
                            @csrf
                            <button type="submit" class="tpx-menu__i tpx-menu__i--danger" role="menuitem"><i class="fas fa-trash-alt" aria-hidden="true"></i><span>Xóa chuyến đi</span></button>
                        </form>
                    @endif
                </div>
            </details>
            <button type="button" id="tpInviteBtn" class="tp-btn tpx-share" aria-label="{{ $role === 'owner' ? 'Mời bạn đồng hành' : 'Người trong chuyến' }} ({{ count($members) }} người)"><i class="fas fa-share-alt" aria-hidden="true"></i> <span class="tpx-share__t">Chia sẻ</span></button>
        </div>
    </div>

    {{-- ---------------- Ảnh bìa + thẻ tên chuyến ---------------- --}}
    <header class="tpx-hero{{ $cover ? ' has-cover' : '' }}" @if ($cover) style="--tpx-cover: url('{{ $cover }}')" @endif>
        <div class="tpx-hero__card">
            <div class="tpx-head__main">
                <h1 id="tpHeading">{{ $t['title'] }}</h1>
                <p class="tpx-meta" id="tpBrief"></p>
            </div>
            <div class="tpx-people">
                <ul class="tpx-avatars" aria-label="Người trong chuyến">
                    @foreach (array_slice($members, 0, 5) as $m)
                        <li class="tpx-av tpx-av--{{ $m['role'] }}" title="{{ $m['name'] }} · {{ ['owner' => 'Chủ chuyến', 'edit' => 'Được chỉnh sửa', 'view' => 'Chỉ xem'][$m['role']] ?? '' }}">{{ mb_strtoupper(mb_substr(trim($m['name']) !== '' ? trim($m['name']) : '?', 0, 1)) }}</li>
                    @endforeach
                    @if (count($members) > 5)<li class="tpx-av tpx-av--more">+{{ count($members) - 5 }}</li>@endif
                    <li><button type="button" class="tpx-av tpx-av--add js-invite" aria-label="{{ $role === 'owner' ? 'Mời bạn đồng hành' : 'Xem người trong chuyến' }}"><i class="fas fa-user-plus" aria-hidden="true"></i></button></li>
                </ul>
                @if ($lastEdit['by'] && $lastEdit['at'])
                    <small class="tpx-lastedit" id="tpLastEdit">{{ $lastEdit['byMe'] ? 'Bạn' : $lastEdit['by'] }} cập nhật lúc {{ \Carbon\Carbon::parse($lastEdit['at'])->format('H:i d/m') }}</small>
                @endif
            </div>
            <ul class="tpx-kpis" id="tpKpis" aria-label="Tổng quan chuyến đi"></ul>
        </div>
    </header>

    @if (session('planner_notice'))
        <p class="tpx-notice" role="status">{{ session('planner_notice') }}</p>
    @endif
    <div id="tpSync" class="tp-banner tpx-notice" role="status" hidden></div>

    <section class="tpux-overview" aria-label="Tình trạng chuyến đi">
        <div class="tpux-overview__intro"><strong>Lên kế hoạch dễ dàng hơn</strong><span>Chọn ngày, sắp xếp điểm đến, kiểm tra cảnh báo rồi lưu chuyến.</span></div>
        <button type="button" id="tpuxHealthBtn" class="tpux-overview__health" aria-controls="tpHealth"><i class="fas fa-heartbeat" aria-hidden="true"></i><span id="tpuxHealthText">Kiểm tra lịch trình</span><i class="fas fa-chevron-right" aria-hidden="true"></i></button>
    </section>
    <div class="tpx-body" id="tpBody">
        {{-- ================= Thanh bên (Wanderlog): Tổng quan · Đã lưu · từng ngày · Ngân sách · Thông tin ================= --}}
        <nav class="tpx-side" id="tpSide" aria-label="Mục lục chuyến đi">
            <div class="tpx-side__in">
                <button type="button" class="tpx-side__b is-on" data-go="top"><i class="fas fa-home" aria-hidden="true"></i><span>Tổng quan</span></button>
                @if ($role !== 'view')
                    <button type="button" class="tpx-side__b" data-go="around"><i class="fas fa-route" aria-hidden="true"></i><span>Tìm quanh tuyến</span></button>
                @endif
                <button type="button" class="tpx-side__b" data-go="x"><i class="fas fa-bookmark" aria-hidden="true"></i><span>Đã lưu</span><b class="tpx-side__n" id="tpSideWait"></b></button>
                <p class="tpx-side__h">Lịch trình</p>
                <ol class="tpx-side__days" id="tpSideDays"></ol>
                <p class="tpx-side__h">Chuyến đi</p>
                <button type="button" class="tpx-side__b" data-go="cost"><i class="fas fa-wallet" aria-hidden="true"></i><span>Ngân sách</span></button>
                <button type="button" class="tpx-side__b" data-go="info"><i class="fas fa-sliders-h" aria-hidden="true"></i><span>Thông tin &amp; chia sẻ</span></button>
                <button type="button" class="tpx-side__hide" id="tpSideHide"><i class="fas fa-angle-double-left" aria-hidden="true"></i><span>Ẩn thanh bên</span></button>
            </div>
        </nav>

        {{-- ================= Cột giữa: lịch trình / chi phí / thông tin ================= --}}
        <section class="tpx-panel" aria-label="Lịch trình chuyến đi">
            <div class="tp-tabs tpx-tabs" role="tablist" aria-label="Các phần của chuyến đi">
                <button type="button" role="tab" class="tp-tab is-on" id="tpTabPlan" aria-controls="tpPanelPlan" aria-selected="true" data-tab="plan"><i class="fas fa-stream" aria-hidden="true"></i> Lịch trình</button>
                <button type="button" role="tab" class="tp-tab" id="tpTabCost" aria-controls="tpPanelCost" aria-selected="false" data-tab="cost"><i class="fas fa-wallet" aria-hidden="true"></i> Chi phí</button>
                <button type="button" role="tab" class="tp-tab" id="tpTabInfo" aria-controls="tpPanelInfo" aria-selected="false" data-tab="info"><i class="fas fa-sliders-h" aria-hidden="true"></i> Thông tin &amp; chia sẻ</button>
            </div>

            {{-- ---------------- Lịch trình ---------------- --}}
            <div class="tp-panel" id="tpPanelPlan" role="tabpanel" aria-labelledby="tpTabPlan">
                <div class="tpux-planhead">
                    <div><h2>Lịch trình từng ngày</h2><p>Chạm vào địa điểm để xem chi tiết; dùng “Sửa” để đổi giờ hoặc thời lượng.</p></div>
                    <button type="button" id="tpuxJumpToday" class="tpux-softbtn">Đến ngày đang xem</button>
                </div>
                <nav class="tpx-days" aria-label="Các ngày">
                    <ol class="tpx-days__list" id="tpDayNav"></ol>
                </nav>

                <div class="tpx-tools">
                    @if ($role !== 'view')
                        <button type="button" class="tpx-addbar" id="tpAddOpen" aria-controls="tpAddDrawer" aria-expanded="false">
                            <i class="fas fa-search" aria-hidden="true"></i>
                            <span>Bạn muốn thêm gì vào chuyến đi?</span>
                            <small>Địa điểm · món ăn · khách sạn · trải nghiệm</small>
                        </button>
                    @endif
                    <div class="tpx-view" role="group" aria-label="Kiểu hiển thị">
                        <button type="button" class="tpx-view__b is-on" data-view="full" aria-pressed="true" title="Hiện ảnh, ghi chú và lý do chọn">Chi tiết</button>
                        <button type="button" class="tpx-view__b" data-view="compact" aria-pressed="false" title="Chỉ giờ và tên, hợp với chuyến nhiều ngày">Gọn</button>
                        <button type="button" class="tpx-view__b" data-view="list" aria-pressed="false" title="Danh sách phẳng mọi điểm, đánh dấu đã ghé khi đang đi">Danh sách</button>
                    </div>
                </div>

                {{-- La bàn F2: lời kể từng ngày + vì sao có mục này + lưu ý có căn cứ. Nằm TRÊN lịch, không thay lịch. --}}
                <section class="ai-story" id="aiStory" aria-labelledby="aiStoryH">
                    <button type="button" class="ai-story__open" id="aiStoryBtn" aria-expanded="false" aria-controls="aiStoryBody">
                        <span class="ai-mark" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none"><circle cx="12" cy="12" r="9.2" stroke="currentColor" stroke-width="1.6"/><path d="M12 4.5 14.2 12 12 19.5 9.8 12Z" fill="#e9a23b"/><path d="M12 12 14.2 12 12 19.5Z" fill="#167A85"/></svg></span>
                        <span><b id="aiStoryH">{{ $aiExplainOn ? 'Tóm tắt chuyến đi bằng AI' : 'Tóm tắt chuyến đi' }}</b><small>Lời kể từng ngày, vì sao có từng nơi, lưu ý có căn cứ</small></span>
                        <i class="fas fa-chevron-down" aria-hidden="true"></i>
                    </button>
                    <div class="ai-story__body" id="aiStoryBody" hidden></div>
                </section>

                <section class="tpx-health" id="tpHealth" aria-labelledby="tpWarnH">
                    <h2 id="tpWarnH" class="tpx-health__h"></h2>
                    <div id="tpWarn"></div>
                </section>

                <div id="tpBulk" class="tp-bulk" hidden></div>
                <div id="tpRouteNote" class="tp-banner" hidden role="status"></div>
                @if ($aiCopilotOn)
                    {{-- La bàn F3: thanh bản nháp khi đang xem đề xuất (viền vàng trên lịch = thay đổi CHƯA áp dụng) --}}
                    <div class="ai-draft" id="aiDraft" role="status" hidden></div>
                @endif
                @if (session('personalized'))
                    {{-- T-29: báo cáo cá nhân hóa của bản sao từ Cộng đồng --}}
                    <aside class="tm-banner tm-banner--ai" role="status" aria-label="La bàn đã điều chỉnh chuyến theo sở thích">
                        <i class="fas fa-compass tm-banner__ico" aria-hidden="true"></i>
                        <div class="tm-banner__txt">
                            <b>La bàn đã điều chỉnh {{ count(session('personalized')) }} mục theo sở thích của bạn</b>
                            <ul class="tm-banner__list">
                                @foreach (session('personalized') as $c)
                                    <li>{{ $c['day'] ? 'Ngày ' . $c['day'] . ': ' : '' }}{{ $c['action'] === 'replace' ? $c['from'] . ' → ' . $c['to'] : $c['from'] . ' → Đã lưu' }} <small>({{ $c['reason'] }})</small></li>
                                @endforeach
                            </ul>
                            <span>Muốn quay lại bản gốc: menu ⋮ → Lịch sử phiên bản.</span>
                        </div>
                    </aside>
                @endif
                @if (!empty($tourBanner))
                    {{-- A-05: có tour giống lịch trình này (Match ≥ ngưỡng, còn đủ chỗ). Bấm đặt: điền sẵn ngày, số người và gắn tripId vào đơn. --}}
                    <aside class="tm-banner" id="tpTourBanner" role="note" aria-label="Có tour giống lịch trình này">
                        <i class="fas fa-suitcase-rolling tm-banner__ico" aria-hidden="true"></i>
                        <div class="tm-banner__txt">
                            <b>Có tour giống lịch trình này · khớp {{ $tourBanner['pct'] }}%</b>
                            <span>{{ $tourBanner['title'] }} · khởi hành {{ $tourBanner['start'] }} · {{ number_format($tourBanner['forGroup'], 0, ',', '.') }}đ cho {{ $t['groupSize'] }} người
                                @if ($tourBanner['matchedPlaces']) · có {{ count($tourBanner['matchedPlaces']) }} địa điểm trong lịch của bạn @endif</span>
                        </div>
                        <div class="tm-banner__act">
                            <a class="tp-btn tp-btn--sm" href="{{ $tourBanner['bookUrl'] }}">Đặt tour</a>
                            <a class="tp-btn tp-btn--ghost tp-btn--sm" href="#tpTourMatch">Vì sao hợp?</a>
                            <button type="button" class="tp-mini tm-banner__x" aria-label="Ẩn gợi ý tour" data-tm-dismiss="{{ $tourBanner['tourId'] }}"><i class="fas fa-times" aria-hidden="true"></i></button>
                        </div>
                    </aside>
                @endif
                <div id="tpDaysBox" class="tpx-timeline"></div>
                <p id="tpTotals" class="tp-totals tpx-totals" role="status"></p>
                <p class="tp-muted tp-small tpx-hint">
                    Giờ trong lịch được xếp tự động từ thứ tự, thời lượng ở lại và quãng đường (bắt đầu 08:00). Bấm <b>Sửa</b> ở một mục để đặt giờ cố định hoặc đổi thời lượng.
                    @if ($role !== 'view') Kéo biểu tượng <i class="fas fa-grip-vertical" aria-hidden="true"></i> để đổi thứ tự hoặc chuyển ngày. Mọi thay đổi tự lưu sau vài giây. @endif
                </p>

                @if (!empty($tourFit))
                    {{-- A-01 Tour Match: Top-N tour + 4 điểm thành phần (Match = 0.35 ngân sách + 0.30 sở thích + 0.20 thời gian + 0.15 nhóm) --}}
                    <details class="tpx-more tp-tourfit" id="tpTourMatch" data-ai-fb="tour_match" open>
                        <summary><i class="fas fa-suitcase-rolling" aria-hidden="true"></i> Tour Travela hợp chuyến này ({{ count($tourFit) }})</summary>
                        <p class="tp-muted tp-small">Điểm khớp = 35% ngân sách + 30% sở thích &amp; địa điểm + 20% thời gian + 15% chỗ cho nhóm. Thành phần ghi “chưa đủ dữ liệu” được tính trung tính 50%.</p>
                        <ul class="tp-tours">
                            @foreach ($tourFit as $tf)
                                <li class="tp-tours__item tm-card" data-tour-match="{{ $tf['tourId'] }}" data-score="{{ $tf['score'] }}">
                                    <div class="tm-card__top">
                                        <a href="{{ $tf['url'] }}"><strong>{{ $tf['title'] }}</strong></a>
                                        <span class="tm-pct {{ $tf['score'] >= \App\Services\Planner\TourMatch::threshold() ? 'is-high' : '' }}" title="Điểm khớp">{{ $tf['pct'] }}%</span>
                                    </div>
                                    <span class="tp-muted tp-small">{{ $tf['destName'] }} · {{ $tf['time'] }} · khởi hành {{ $tf['start'] }} · còn {{ $tf['remaining'] }} chỗ</span>
                                    <span class="tp-small">Cho nhóm: {{ number_format($tf['forGroup'], 0, ',', '.') }}đ</span>
                                    <ul class="tm-bars">
                                        @foreach ($tf['components'] as $k => $c)
                                            <li class="{{ $c['known'] ? '' : 'is-unknown' }}">
                                                <span class="tm-bars__l">{{ $c['label'] }} <small>×{{ rtrim(rtrim(number_format($c['weight'], 2, ',', ''), '0'), ',') }}</small></span>
                                                <span class="tm-bars__bar" aria-hidden="true"><i style="width: {{ (int) round($c['value'] * 100) }}%"></i></span>
                                                <span class="tm-bars__v">{{ $c['known'] ? (int) round($c['value'] * 100) . '%' : 'chưa đủ dữ liệu' }}</span>
                                                <small class="tm-bars__n">{{ $c['note'] }}</small>
                                            </li>
                                        @endforeach
                                    </ul>
                                    @if ($role !== 'view' && $tf['enoughSeats'])
                                        <a class="tp-btn tp-btn--sm" href="{{ $tf['bookUrl'] }}">Đặt tour này với ngày và số người của chuyến</a>
                                    @endif
                                </li>
                            @endforeach
                        </ul>
                    </details>
                @endif
                @if ($role !== 'view')
                    <details class="tpx-more tp-suggbox">
                        <summary><i class="fas fa-lightbulb" aria-hidden="true"></i> Gợi ý thêm cho chuyến này</summary>
                        <div id="tpSugg"></div>
                    </details>
                @else
                    <div id="tpSugg" hidden></div>
                @endif
            </div>

            {{-- ---------------- Chi phí ---------------- --}}
            <div class="tp-panel" id="tpPanelCost" role="tabpanel" aria-labelledby="tpTabCost" hidden>
                <div id="tpCostBox"></div>
            </div>

            {{-- ---------------- Thông tin & chia sẻ ---------------- --}}
            <div class="tp-panel" id="tpPanelInfo" role="tabpanel" aria-labelledby="tpTabInfo" hidden>
                <section class="tp-card">
                    <h2>Thông tin chuyến đi</h2>
                    <div class="tp-form tp-form--grid">
                        <label>Tên chuyến đi
                            <input type="text" id="tpTitle" value="{{ $t['title'] }}" maxlength="160">
                        </label>
                        <label>Ngày đi
                            <input type="date" id="tpStart" value="{{ $t['startDate'] }}">
                        </label>
                        <label>Số ngày
                            <input type="number" id="tpDays" min="1" max="{{ \App\Services\Planner\TripPlanner::MAX_DAYS }}" value="{{ $t['days'] }}">
                        </label>
                        <label>Số người
                            <input type="number" id="tpGroup" min="1" max="50" value="{{ $t['groupSize'] }}">
                        </label>
                        <label>Ngân sách (đ)
                            <input type="number" id="tpBudget" min="0" step="1000" value="{{ $t['budget'] !== null ? (int) $t['budget'] : '' }}">
                        </label>
                        <label>Điểm đến chính
                            <select class="no-nice" id="tpDest"><option value="">Chưa chọn</option></select>
                        </label>
                    </div>
                    <p class="tp-muted tp-small">Điểm đến chính giúp tìm gần đúng nơi bạn đi và ưu tiên nội dung của nơi đó. Giảm số ngày thì các mục ở ngày bị cắt được chuyển về ngày cuối, bạn có thể hoàn tác.</p>
                </section>

                <section class="tp-card">
                    <h2>Tổng quan</h2>
                    <div id="tpStats" class="tp-stats" aria-label="Thống kê chuyến đi"></div>
                    <div id="tpSummary"></div>
                </section>

                <section class="tp-card tp-travel" id="tpTravel" data-flights="{{ route('travel.flights', $t['id']) }}" data-hotels="{{ route('travel.hotels', $t['id']) }}"
                         data-status="{{ route('travel.status', $t['id']) }}" data-remove="{{ url('/len-ke-hoach/' . $t['id'] . '/ngoai') }}" data-csrf="{{ csrf_token() }}" data-edit="{{ $role !== 'view' ? 1 : 0 }}">
                    <h2>Chuyến bay và khách sạn</h2>
                    <p class="tp-muted tp-small">Tham khảo giá vé và chỗ ở để tính ngân sách; Travela không bán vé hay đặt phòng. Mục bạn thêm vào lịch trình có chi phí ước tính.
                        @if ($travel['simulateOnly'])<span class="tp-sim">Dữ liệu thử nghiệm</span>@endif</p>

                    @if (count($externals))
                        <ul class="tp-ext" id="tpExtList">
                            @foreach ($externals as $x)
                                <li data-id="{{ $x->externalId }}">
                                    <i class="fas {{ $x->kind === 'flight' ? 'fa-plane' : ($x->kind === 'ticket' ? 'fa-ticket-alt' : 'fa-hotel') }}" aria-hidden="true"></i>
                                    <span><b>{{ $x->title }}</b><small>{{ \Carbon\Carbon::parse($x->startAt)->format('H:i d/m/Y') }}{{ $x->price ? ' · ' . number_format((float) $x->price, 0, ',', '.') . ' đ' : '' }}{{ $x->simulated ? ' · Dữ liệu thử nghiệm' : '' }}{{ !empty($x->confirmationCode) ? ' · mã ' . $x->confirmationCode : '' }}</small>
                                        @if (!empty($x->attachPath))<a class="tp-small" href="{{ route('travel.confirm.file', [$t['id'], $x->externalId]) }}" target="_blank" rel="noopener"><i class="fas fa-paperclip" aria-hidden="true"></i> {{ $x->attachName ?: 'Tệp xác nhận' }}</a>@endif</span>
                                    <span class="tp-ext__st" data-status-for="{{ $x->externalId }}"></span>
                                    @if ($role !== 'view')<button type="button" class="tp-mini tp-ext__rm" aria-label="Bỏ {{ $x->title }} khỏi danh sách">Bỏ</button>@endif
                                </li>
                            @endforeach
                        </ul>
                    @endif

                    @if ($role !== 'view')
                        {{-- A-12: xác nhận đặt chỗ đã đặt ở nơi khác (Booking, Agoda, hãng bay...) + tệp PDF / ảnh, lưu riêng tư --}}
                        <details class="tp-travel__f tp-confirm">
                            <summary><i class="fas fa-file-invoice" aria-hidden="true"></i> Lưu xác nhận đặt chỗ đã có</summary>
                            <form id="tpConfirmForm" data-url="{{ route('travel.confirm', $t['id']) }}" enctype="multipart/form-data">
                                <div class="tp-travel__row">
                                    <label>Loại<select name="kind" class="no-nice"><option value="hotel">Khách sạn</option><option value="flight">Vé máy bay / xe</option><option value="ticket">Vé tham quan</option></select></label>
                                    <label>Tên<input type="text" name="title" maxlength="120" required placeholder="Ví dụ: Dalat Palace 2 đêm"></label>
                                    <label>Nơi đặt<input type="text" name="provider" maxlength="20" placeholder="Booking, Agoda, VNA…"></label>
                                </div>
                                <div class="tp-travel__row">
                                    <label>Mã xác nhận<input type="text" name="code" maxlength="40" placeholder="ABC123"></label>
                                    <label>Ngày giờ<input type="datetime-local" name="startAt" required value="{{ $travel['date'] }}T14:00"></label>
                                    <label>Đã trả (đ)<input type="number" name="price" min="0" step="1000"></label>
                                </div>
                                <label class="tp-small">Tệp xác nhận (PDF, JPG, PNG, WebP, tối đa 5 MB)<input type="file" name="file" accept=".pdf,image/jpeg,image/png,image/webp"></label>
                                <button type="submit" class="tp-btn tp-btn--ghost">Lưu xác nhận</button>
                                <p class="tp-small tp-confirm__msg" role="status"></p>
                            </form>
                        </details>
                    @endif

                    <form class="tp-travel__f" id="tpFlightForm">
                        <h3><i class="fas fa-plane-departure" aria-hidden="true"></i> Tìm chuyến bay</h3>
                        <div class="tp-travel__row">
                            <label>Từ<select name="from" class="no-nice">@foreach ($travel['airports'] as $code => $n)<option value="{{ $code }}" @selected($travel['from'] === $code)>{{ $code }} · {{ preg_replace('/\s*\(.*\)$/', '', $n) }}</option>@endforeach</select></label>
                            <label>Đến<select name="to" class="no-nice">@foreach ($travel['airports'] as $code => $n)<option value="{{ $code }}" @selected($travel['to'] === $code)>{{ $code }} · {{ preg_replace('/\s*\(.*\)$/', '', $n) }}</option>@endforeach</select></label>
                            <label>Ngày bay<input type="date" name="date" value="{{ $travel['date'] }}" min="{{ today()->toDateString() }}"></label>
                            <label>Số khách<input type="number" name="adults" min="1" max="9" value="{{ min(9, $travel['adults']) }}"></label>
                        </div>
                        <button type="submit" class="tp-btn tp-btn--ghost">Tìm vé</button>
                        <div class="tp-travel__res" aria-live="polite"></div>
                    </form>

                    <form class="tp-travel__f" id="tpHotelForm" data-city="{{ $travel['city'] ?? '' }}">
                        <h3><i class="fas fa-bed" aria-hidden="true"></i> Khách sạn gần điểm đến</h3>
                        <div class="tp-travel__row">
                            <label>Nhận phòng<input type="date" name="checkIn" value="{{ $travel['date'] }}"></label>
                            <label>Số đêm<input type="number" name="nights" min="1" max="14" value="{{ $travel['nights'] }}"></label>
                            <label>Số khách<input type="number" name="adults" min="1" max="20" value="{{ $travel['adults'] }}"></label>
                        </div>
                        <button type="submit" class="tp-btn tp-btn--ghost">Tìm khách sạn</button>
                        @if (!empty($travel['city']))
                            {{-- deep-link: mở trang tìm phòng thật với cùng điểm đến, ngày và số khách (Travela không đặt phòng) --}}
                            <p class="tp-small tp-travel__deep">Đặt phòng thật trên
                                <a href="#" data-deep="booking" target="_blank" rel="noopener nofollow">Booking.com</a> ·
                                <a href="#" data-deep="agoda" target="_blank" rel="noopener nofollow">Agoda</a></p>
                        @endif
                        <div class="tp-travel__res" aria-live="polite"></div>
                    </form>
                    <p class="tp-muted tp-small" style="margin-top:10px">Nguồn khi có khóa API: giá vé Duffel, trạng thái chuyến bay AeroDataBox / AviationStack, khách sạn Geoapify (giá phòng là ước tính theo hạng sao).</p>
                </section>

                <section class="tp-card tp-pack" id="tpPack" data-trip="{{ $t['id'] }}" data-url="{{ route('planner.packing', ['id' => $t['id']]) }}">
                    <div class="tp-pack__head">
                        <h2>Đồ mang theo</h2>
                        <span class="tp-pack__count tp-muted tp-small" aria-live="polite"></span>
                    </div>
                    <p class="tp-muted tp-small tp-pack__status">Danh sách theo điểm đến, khí hậu tháng đi, số ngày và các hoạt động trong chuyến. Đánh dấu món đã chuẩn bị (lưu trên máy này).</p>
                    <div class="tp-pack__list"></div>
                    <button type="button" class="tp-btn tp-btn--ghost tp-pack__go"><i class="fas fa-suitcase-rolling" aria-hidden="true"></i> Gợi ý đồ mang theo</button>
                </section>

                <section class="tp-card">
                    <h2>Chia sẻ</h2>
                    <p class="tp-muted tp-small">Mời bạn đồng hành bằng email tài khoản Travela, chọn quyền “Có thể chỉnh sửa” hoặc “Chỉ xem”.</p>
                    <button type="button" class="tp-btn tp-btn--ghost js-invite"><i class="fas fa-user-plus" aria-hidden="true"></i> {{ $role === 'owner' ? 'Mời bạn đồng hành' : 'Xem người trong chuyến' }}</button>
                    @if ($role === 'owner')
                        <h3>Liên kết chỉ xem</h3>
                        <p class="tp-muted tp-small">Ai có liên kết đều xem được bản chỉ đọc (không cần tài khoản). Tắt liên kết thì liên kết cũ hết hiệu lực.</p>
                        <div class="tp-share">
                            <input type="text" id="tpShareUrl" readonly aria-label="Liên kết chia sẻ" value="{{ $t['shared'] ? route('planner.shared', ['token' => $t['shareToken']]) : '' }}" @if (!$t['shared']) hidden @endif>
                            <button type="button" id="tpShareBtn" class="tp-btn tp-btn--ghost">{{ $t['shared'] ? 'Tắt liên kết' : 'Bật liên kết chỉ xem' }}</button>
                        </div>

                        <h3 id="cong-dong">Chia sẻ lên Cộng đồng</h3>
                        @if ($t['isPublic'])
                            <p class="tp-muted tp-small">Đang hiển thị trên <a href="{{ route('community.show', $t['id']) }}">trang Cộng đồng</a> · {{ $t['likeCount'] }} lượt thích · {{ $t['cloneCount'] }} lượt dùng lại. Người khác chỉ xem được lịch trình và tên rút gọn của bạn; ghi chú chi phí thực tế, thành viên và đơn đặt tour không hiển thị.</p>
                        @else
                            <p class="tp-muted tp-small">Cho mọi người xem và dùng lại lịch trình này. Thôi chia sẻ lúc nào cũng được; bản người khác đã dùng lại vẫn giữ nguyên.</p>
                        @endif
                        <form method="POST" action="{{ route('community.publish', $t['id']) }}" class="tp-form">
                            @csrf
                            @if ($t['isPublic'])
                                <input type="hidden" name="action" value="unpublish">
                                <button type="submit" class="tp-btn tp-btn--ghost"><i class="fas fa-eye-slash" aria-hidden="true"></i> Thôi chia sẻ</button>
                            @else
                                <input type="hidden" name="action" value="publish">
                                <label>Giới thiệu ngắn (không bắt buộc)
                                    <textarea name="summary" maxlength="300" rows="2" placeholder="VD: 3 ngày Đà Lạt nhẹ nhàng cho gia đình có trẻ nhỏ, nhiều điểm trong nhà khi mưa.">{{ $t['summary'] }}</textarea>
                                </label>
                                <button type="submit" class="tp-btn"><i class="fas fa-globe-asia" aria-hidden="true"></i> Chia sẻ lên Cộng đồng</button>
                            @endif
                        </form>
                    @endif
                </section>
            </div>
        </section>

        {{-- ================= Cột phải: bản đồ ================= --}}
        <section class="tpx-mapcol" id="tpMapCol" aria-labelledby="tpMapH">
            <h2 id="tpMapH" class="visually-hidden">Bản đồ</h2>
            <div class="tpx-mapbar">
                <label class="tpx-mapbar__sel">
                    <i class="fas fa-layer-group" aria-hidden="true"></i>
                    <select class="no-nice" id="tpMapDay" aria-label="Chọn ngày hiển thị trên bản đồ"></select>
                </label>
                <button type="button" class="tpx-mapbtn" id="tpWide" aria-pressed="false" title="Thu gọn lịch để bản đồ rộng hơn"><i class="fas fa-expand-alt" aria-hidden="true"></i><span>Mở rộng</span></button>
                <button type="button" class="tpx-mapbtn tpx-mapclose" id="tpMapClose" aria-label="Đóng bản đồ"><i class="fas fa-times" aria-hidden="true"></i></button>
            </div>
            <div class="tp-mapwrap tpx-mapwrap">
                <div id="tpMap" class="tp-map" role="application" aria-label="Bản đồ các điểm trong chuyến đi"></div>
                <div id="tpPlaceCard" class="tp-pcard" role="dialog" aria-label="Chi tiết địa điểm" hidden></div>
                <ol class="tpx-legend" id="tpLegend" aria-label="Chú thích màu theo ngày"></ol>
                @if ($aiCopilotOn)
                    <button type="button" class="ai-co-fab" id="aiCoOpen2" aria-controls="aiCo"><span class="ai-mark ai-mark--sm" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none"><circle cx="12" cy="12" r="9.2" stroke="currentColor" stroke-width="1.6"/><path d="M12 4.5 14.2 12 12 19.5 9.8 12Z" fill="#e9a23b"/><path d="M12 12 14.2 12 12 19.5Z" fill="#167A85"/></svg></span>Hỏi La bàn</button>
                @endif
            </div>
            {{-- Điện thoại: bảng trượt dưới bản đồ toàn màn hình (lịch của ngày đang xem, chạm để xem trên bản đồ) --}}
            <div class="tpx-msheet" id="tpMapSheet" aria-label="Lịch của ngày trên bản đồ"></div>
        </section>
    </div>

    <div class="tpx-backdrop" id="tpAddBack" hidden></div>
    {{-- ================= Ngăn "Thêm vào chuyến đi" (tìm theo tên, theo tiêu chí, tự nhập) ================= --}}
    <aside class="tpx-drawer" id="tpAddDrawer" aria-labelledby="tpAddH" hidden>
        <div class="tpx-drawer__head">
            <h2 id="tpAddH">Thêm vào chuyến đi</h2>
            <button type="button" class="tpx-icon" id="tpAddClose" aria-label="Đóng"><i class="fas fa-times" aria-hidden="true"></i></button>
        </div>
        <div class="tpx-dtabs" role="tablist" aria-label="Cách tìm">
            <button type="button" role="tab" class="tpx-dtab is-on" id="tpDtabFind" data-dtab="find" aria-selected="true" aria-controls="tpAdd">Tìm theo tên, nhu cầu</button>
            <button type="button" role="tab" class="tpx-dtab" id="tpDtabAround" data-dtab="around" aria-selected="false" aria-controls="tpAround"><i class="fas fa-route" aria-hidden="true"></i> Quanh tuyến</button>
        </div>
        {{-- Quanh tuyến (Roadtrippers "Search results within miles of your route"): planner.js vẽ --}}
        <div class="tpx-drawer__body tpx-around" id="tpAround" role="tabpanel" aria-labelledby="tpDtabAround" hidden></div>
        <div class="tp-add tpx-drawer__body" id="tpAdd" role="tabpanel" aria-labelledby="tpDtabFind">
            <div class="tpx-search">
                <i class="fas fa-search" aria-hidden="true"></i>
                <input type="search" id="tpQ" maxlength="80" autocomplete="off" placeholder="Tên nơi (Hồ Gươm, phở Thìn) hoặc mong muốn (quán chay giá rẻ)" aria-label="Tìm để thêm vào chuyến đi" aria-controls="tpResults">
            </div>
            <div class="tpx-target">
                <span class="tp-small tp-muted">Thêm vào</span>
                <div class="tpx-target__chips" id="tpTargetChips" role="radiogroup" aria-label="Ngày sẽ thêm vào"></div>
                <select class="no-nice visually-hidden" id="tpTarget" tabindex="-1" aria-hidden="true"></select>
            </div>
            <label class="tp-check tp-small" id="tpDestOnlyBox" hidden><input type="checkbox" id="tpDestOnly" checked> Chỉ tìm nội dung của <b id="tpDestName"></b> trong kho Travela</label>
            <div class="tp-chips tpx-cats" id="tpPresets"></div>
            <div class="tp-browse" id="tpBrowse" role="region" aria-label="Tìm theo tiêu chí" aria-live="polite" hidden></div>
            <div class="tp-results" id="tpResults" role="region" aria-live="polite" hidden></div>

            <details class="tp-manual" id="tpManual">
                <summary>Không tìm thấy? Tự thêm địa điểm, di chuyển hoặc ghi chú</summary>
                <p class="tp-muted tp-small">Dùng cho nơi chưa có trên Travela hay trên bản đồ. Thêm vị trí (dán link Google Maps hoặc bấm trên bản đồ) để mục hiện trên bản đồ và được tính quãng đường.</p>
                <form id="tpCustomForm" class="tp-custom">
                    <input type="text" id="tpCustomLabel" maxlength="120" placeholder="Tên mục (ví dụ: Khách sạn Sen Vàng)" aria-label="Tên mục tự nhập" required>
                    <select class="no-nice" id="tpCustomKind" aria-label="Loại mục"><option value="note">Địa điểm / lưu trú / ăn uống / ghi chú</option><option value="transport">Di chuyển</option></select>
                    <input type="number" id="tpCustomCost" min="0" step="1000" placeholder="Chi phí (đ)" aria-label="Chi phí">
                    <button class="tp-btn tp-btn--ghost" type="submit">+ Thêm</button>
                    <div class="tp-custom__pos">
                        <input type="text" id="tpCustomLink" autocomplete="off" placeholder="Vị trí (không bắt buộc): dán link Google Maps hoặc tọa độ, ví dụ 21.0285, 105.8542" aria-label="Vị trí của mục tự nhập">
                        <button type="button" class="tp-mini" id="tpPickMap">Chọn trên bản đồ</button>
                        <button type="button" class="tp-mini tp-mini--danger" id="tpPosClear" hidden>Bỏ vị trí</button>
                        <p class="tp-small tp-muted" id="tpPosMsg" role="status" aria-live="polite"></p>
                    </div>
                </form>
            </details>
        </div>
    </aside>

    @if ($aiCopilotOn)
        {{-- ================= La bàn Copilot: ngăn phải đè lên bản đồ (desktop), toàn màn hình (điện thoại). planner-ai-copilot.js vẽ ================= --}}
        <aside class="ai-co" id="aiCo" aria-labelledby="aiCoH" aria-modal="false" role="dialog" tabindex="-1" hidden>
            <header class="ai-co__h">
                <span class="ai-mark" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none"><circle cx="12" cy="12" r="9.2" stroke="currentColor" stroke-width="1.6"/><path d="M12 4.5 14.2 12 12 19.5 9.8 12Z" fill="#e9a23b"/><path d="M12 12 14.2 12 12 19.5Z" fill="#167A85"/></svg></span>
                <div><b id="aiCoH">La bàn AI</b><small>Đề xuất chỉ được áp dụng khi bạn xác nhận</small></div>
                <button type="button" class="tpx-icon" id="aiCoClose" aria-label="Đóng La bàn"><i class="fas fa-times" aria-hidden="true"></i></button>
            </header>
            <div class="tpux-ai-trust"><i class="fas fa-shield-alt" aria-hidden="true"></i> AI có thể ước tính sai. Hãy xem lại thay đổi trước khi áp dụng.</div>
            <div class="ai-co__ctx" id="aiCoCtx"></div>
            <div class="ai-co__topics" id="aiCoTopics" role="toolbar" aria-label="Gợi ý nhanh cho chuyến"></div>
            <div class="ai-co__feed" id="aiCoFeed" aria-live="polite"></div>
            <div class="ai-co__comp">
                <div class="ai-co__busy" id="aiCoBusy" hidden><span>La bàn đang xử lý… <b id="aiCoBusyT">0s</b></span><button type="button" class="tp-mini" id="aiCoCancel">Hủy</button></div>
                <div class="ai-co__sug" id="aiCoSug"></div>
                <form class="ai-co__box" id="aiCoForm">
                    <span class="ai-co__chips" id="aiCoChips"></span>
                    <input id="aiCoQ" maxlength="400" autocomplete="off" placeholder="Ví dụ: gợi ý món ăn · ngày 2 nhẹ hơn · có lễ hội gì không?" aria-label="Yêu cầu cho La bàn">
                    <button type="button" class="ai-co__icon" id="aiCoPick" aria-pressed="false" title="Chạm vào một mục trong lịch để nhắc tới nó" aria-label="Chọn mục trong lịch">@</button>
                    <button type="submit" class="ai-co__icon ai-co__icon--send" aria-label="Gửi"><i class="fas fa-paper-plane" aria-hidden="true"></i></button>
                </form>
                <p class="ai-co__hint">Bấm <b>@</b> rồi chạm vào một mục trong lịch để nhắc tới nó. Câu bạn gửi đi kèm bảng lịch (tên nơi, giờ), không kèm email hay tên người trong chuyến.</p>
            </div>
        </aside>
    @endif

    {{-- ================= Thanh thao tác trên điện thoại ================= --}}
    <nav class="tpx-mob" aria-label="Thao tác nhanh">
        <button type="button" class="tpx-mob__b is-on" data-mob="plan"><i class="fas fa-stream" aria-hidden="true"></i><span>Lịch trình</span></button>
        <button type="button" class="tpx-mob__b" data-mob="map"><i class="fas fa-map-marked-alt" aria-hidden="true"></i><span>Bản đồ</span></button>
        @if ($role !== 'view')<button type="button" class="tpx-mob__b tpx-mob__add" data-mob="add" aria-label="Thêm vào chuyến đi"><i class="fas fa-plus" aria-hidden="true"></i></button>@endif
        @if ($aiCopilotOn)<button type="button" class="tpx-mob__b tpux-mob-ai" id="tpuxMobAi" aria-controls="aiCo" aria-expanded="false"><i class="fas fa-compass" aria-hidden="true"></i><span>AI</span></button>@else
        <button type="button" class="tpx-mob__b" data-mob="cost"><i class="fas fa-wallet" aria-hidden="true"></i><span>Chi phí</span></button>@endif
        <button type="button" class="tpx-mob__b" data-mob="info"><i class="fas fa-sliders-h" aria-hidden="true"></i><span>Thông tin</span></button>
    </nav>
</main>

<div id="tpMembers" class="tp-modal" role="dialog" aria-modal="true" aria-labelledby="tpMembersH" hidden>
    <div class="tp-modal__box">
        <div class="tp-modal__head">
            <h2 id="tpMembersH">{{ $role === 'owner' ? 'Mời bạn đồng hành' : 'Người trong chuyến' }}</h2>
            <button type="button" id="tpMembersClose" class="tp-mini" aria-label="Đóng">✕</button>
        </div>
        @if ($role === 'owner')
            <form id="tpInvForm" class="tp-inv">
                <input type="email" id="tpInvEmail" maxlength="190" placeholder="Email tài khoản Travela của bạn bè" aria-label="Email người được mời" required>
                <select class="no-nice" id="tpInvRole" aria-label="Quyền"><option value="edit">Có thể chỉnh sửa</option><option value="view">Chỉ xem</option></select>
                <button type="submit" class="tp-btn">Mời</button>
            </form>
            <p class="tp-muted tp-small">Người được mời cần có tài khoản Travela. Họ sẽ thấy chuyến đi trong “Chuyến đi của tôi”. Bạn đổi quyền hoặc gỡ họ bất cứ lúc nào.</p>
        @endif
        <p id="tpInvMsg" class="tp-msg" role="status" aria-live="polite"></p>
        <ul id="tpMemList" class="tp-memlist"></ul>
    </div>
</div>

@if (!empty($client['historyUrl']))
{{-- Lịch sử phiên bản: planner-history.js vẽ danh sách + xem trước + khôi phục (tạo phiên bản mới, hoàn tác được) --}}
<div id="tpHist" class="tp-modal tph" role="dialog" aria-modal="true" aria-labelledby="tpHistH" hidden>
    <div class="tp-modal__box tph__box">
        <div class="tp-modal__head">
            <div><h2 id="tpHistH"><i class="fas fa-history" aria-hidden="true"></i> Lịch sử phiên bản</h2>
                <p class="tp-muted tp-small tph__sub">Mỗi lần lưu là một phiên bản. Khôi phục tạo ra phiên bản mới, nên bạn luôn quay lại được.</p></div>
            <button type="button" id="tpHistClose" class="tp-mini" aria-label="Đóng">✕</button>
        </div>
        <div class="tph__body">
            <ol class="tph__list" id="tpHistList" aria-label="Các phiên bản, mới nhất trước"></ol>
            <section class="tph__view" id="tpHistView" aria-live="polite"></section>
        </div>
    </div>
</div>
@endif

<script type="application/json" id="tpData">{!! json_encode($client, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) !!}</script>
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
{{-- planner-geo (tuyến) + planner-schedule (xếp giờ): lõi tính xem trước, tham số từ cfg.rules (PlannerRules); planner-api: gọi máy chủ; planner.js: giao diện --}}
<script src="{{ asset('clients/assets/js/planner-geo.js') }}?v=4"></script>
<script src="{{ asset('clients/assets/js/planner-schedule.js') }}?v=6"></script>
<script src="{{ asset('clients/assets/js/planner-api.js') }}?v=2"></script>
<script src="{{ asset('clients/assets/js/planner-save.js') }}?v=3"></script>
<script src="{{ asset('clients/assets/js/planner-resolve.js') }}?v=2"></script>
<script src="{{ asset('clients/assets/js/planner-map.js') }}?v=5"></script>
<script src="{{ asset('clients/assets/js/planner.js') }}?v=26" defer></script>
<script src="{{ asset('clients/assets/js/planner-extras.js') }}?v=2" defer></script>
<script src="{{ asset('clients/assets/js/planner-travel.js') }}?v=2" defer></script>
@if (!empty($client['historyUrl']))<script src="{{ asset('clients/assets/js/planner-history.js') }}?v=1" defer></script>@endif
@if ($role !== 'view')<script src="{{ asset('clients/assets/js/ai-feedback.js') }}?v=2" data-url="{{ route('planner.ai.rate') }}" data-csrf="{{ csrf_token() }}" data-trip="{{ $client['trip']['id'] ?? '' }}" defer></script>@endif
<script type="application/json" id="aiData">{!! json_encode(['explainUrl' => route('planner.ai.explain', ['id' => $t['id']]), 'csrf' => csrf_token(), 'on' => $aiExplainOn,
    'copilot' => $aiCopilotOn ? ['url' => route('planner.ai.copilot', ['id' => $t['id']]), 'recheckUrl' => route('planner.ai.recheck', ['id' => $t['id']]),
        'feedbackUrl' => route('planner.ai.feedback', ['id' => $t['id']]),
        'suggestUrl' => config('ai.features.suggest', true) ? route('planner.ai.suggest', ['id' => $t['id']]) : null, 'suggestApplyUrl' => route('planner.ai.suggest.apply', ['id' => $t['id']]),
        'llm' => (bool) ($aiLlmOn ?? true)] : null], JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) !!}</script>
<script src="{{ asset('clients/assets/js/planner-ai-explain.js') }}?v=1" defer></script>
<script src="{{ asset('clients/assets/js/planner-ux.js') }}?v=1" defer></script>
@if ($aiCopilotOn)
    <script src="{{ asset('clients/assets/js/planner-ai-copilot.js') }}?v=3" defer></script>
@endif

@include('clients.blocks.footer')
