@include('clients.blocks.header')
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css">
<link rel="stylesheet" href="{{ asset('clients/assets/css/planner.css') }}?v=9">

{{-- /len-ke-hoach/{id}. Biến: $payload (TripPlanner::payload), $client (dữ liệu cho planner.js).
     Toàn bộ danh sách ngày, tab Khám phá, tab Chi phí và phần Tóm tắt do planner.js vẽ từ #tpData; Blade chỉ dựng khung và các ô thông tin. --}}
@php
    $t = $payload['trip'];
@endphp

<main class="tp tp--edit">
    <div class="container">
        <header class="tp-head">
            <p><a href="{{ route('planner') }}">← Chuyến đi của tôi</a></p>
            <h1 id="tpHeading">{{ $t['title'] }}</h1>
        </header>
        @if (session('planner_notice'))
            <p class="tp-card" role="status">{{ session('planner_notice') }}</p>
        @endif

        <div class="tp-bar" role="toolbar" aria-label="Công cụ chuyến đi">
            <button type="button" id="tpUndo" class="tp-mini" disabled title="Hoàn tác (Ctrl+Z)">↶ Hoàn tác</button>
            <button type="button" id="tpRedo" class="tp-mini" disabled title="Làm lại (Ctrl+Shift+Z)">↷ Làm lại</button>
            <span id="tpMsg" class="tp-msg" role="status" aria-live="polite"></span>
            @if ($role === 'view')<span class="tp-role tp-role--view">Chỉ xem</span>@endif
            <button type="button" id="tpInviteBtn" class="tp-btn tp-btn--ghost">{{ $role === 'owner' ? 'Mời bạn đồng hành' : 'Người trong chuyến' }} ({{ count($members) }})</button>
            @if ($role !== 'view')<button type="button" id="tpSave" class="tp-btn">Lưu thay đổi</button>@else<button type="button" id="tpSave" class="tp-btn" hidden>Lưu thay đổi</button>@endif
        </div>
        <div id="tpSync" class="tp-banner" role="status" hidden></div>

        <div class="tp-edit">
            <nav class="tp-side" aria-label="Điều hướng chuyến đi">
                <div class="tp-tabs" role="tablist" aria-label="Các phần của chuyến đi">
                    <button type="button" role="tab" class="tp-tab is-on" id="tpTabPlan" aria-controls="tpPanelPlan" aria-selected="true" data-tab="plan">Lịch trình</button>
                    <button type="button" role="tab" class="tp-tab" id="tpTabCost" aria-controls="tpPanelCost" aria-selected="false" data-tab="cost">Chi phí</button>
                    <button type="button" role="tab" class="tp-tab" id="tpTabInfo" aria-controls="tpPanelInfo" aria-selected="false" data-tab="info">Thông tin</button>
                </div>
                <p class="tp-side__h">Các ngày</p>
                <ol class="tp-daynav" id="tpDayNav"></ol>
            </nav>
            <div class="tp-col-main">
                {{-- ---------------- Lịch trình: ô tìm để thêm + các ngày ---------------- --}}
                <section class="tp-card tp-panel" id="tpPanelPlan" role="tabpanel" aria-labelledby="tpTabPlan">
                    <p class="tp-brief" id="tpBrief"></p>

                    <div class="tp-add" id="tpAdd">
                        <div class="tp-add__bar">
                            <input type="search" id="tpQ" maxlength="80" autocomplete="off" placeholder="Tìm theo tên (Hồ Gươm, phở Thìn) hoặc theo tiêu chí (nhà hàng chay giá rẻ, khách sạn 4 sao dưới 1 triệu)" aria-label="Tìm để thêm vào chuyến đi" aria-controls="tpResults">
                            <label class="tp-add__to">Thêm vào
                                <select class="no-nice" id="tpTarget"></select>
                            </label>
                        </div>
                        <label class="tp-check tp-small" id="tpDestOnlyBox" hidden><input type="checkbox" id="tpDestOnly" checked> Chỉ tìm nội dung của <b id="tpDestName"></b> trong kho Travela</label>
                        <div class="tp-chips" id="tpPresets"></div>
                        <div class="tp-browse" id="tpBrowse" role="region" aria-label="Tìm theo tiêu chí" aria-live="polite" hidden></div>
                        <div class="tp-results" id="tpResults" role="region" aria-live="polite" hidden></div>

                        <details class="tp-manual" id="tpManual">
                            <summary>Không tìm thấy? Tự nhập mục của riêng bạn</summary>
                            <p class="tp-muted tp-small">Dùng cho nơi chưa có trên Travela hay trên bản đồ. Thêm vị trí (dán link Google Maps hoặc bấm trên bản đồ) để mục hiện trên bản đồ và được tính quãng đường.</p>
                            <form id="tpCustomForm" class="tp-custom">
                                <input type="text" id="tpCustomLabel" maxlength="120" placeholder="Tên mục (ví dụ: Khách sạn Sen Vàng)" aria-label="Tên mục tự nhập" required>
                                <select class="no-nice" id="tpCustomKind" aria-label="Loại mục"><option value="note">Ghi chú / lưu trú / ăn uống</option><option value="transport">Di chuyển</option></select>
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

                    <div class="tp-planhead">
                        <h2>Lịch theo ngày</h2>
                        <label class="tp-check tp-small"><input type="checkbox" id="tpKeepFirst" checked> Giữ nguyên mục đầu của ngày khi “Tối ưu tuyến”</label>
                    </div>
                    <p class="tp-muted tp-small">Kéo biểu tượng ⠿ để đổi thứ tự hoặc chuyển ngày (trên điện thoại dùng nút ↑ ↓ và ô chọn ngày). Mọi thay đổi tự lưu sau vài giây.</p>
                    <div id="tpBulk" class="tp-bulk" hidden></div>
                    <div id="tpRouteNote" class="tp-banner" hidden role="status"></div>
                    <div id="tpDaysBox"></div>
                    <p id="tpTotals" class="tp-totals" role="status"></p>

                    <details class="tp-suggbox">
                        <summary>Gợi ý thêm cho chuyến này</summary>
                        <div id="tpSugg"></div>
                    </details>
                </section>

                {{-- ---------------- Chi phí ---------------- --}}
                <section class="tp-card tp-panel" id="tpPanelCost" role="tabpanel" aria-labelledby="tpTabCost" hidden>
                    <h2>Chi phí dự kiến</h2>
                    <div id="tpCostBox"></div>
                </section>

                {{-- ---------------- Thông tin & chia sẻ ---------------- --}}
                <section class="tp-card tp-panel" id="tpPanelInfo" role="tabpanel" aria-labelledby="tpTabInfo" hidden>
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

                    <div id="tpStats" class="tp-stats" aria-label="Thống kê chuyến đi"></div>
                    <div id="tpSummary"></div>

                    @if ($role === 'owner')
                        <h3>Liên kết chỉ xem</h3>
                        <p class="tp-muted tp-small">Tùy chọn phụ: ai có liên kết đều xem được bản chỉ đọc (không cần tài khoản). Muốn cho người khác cùng chỉnh sửa, dùng “Mời bạn đồng hành”. Tắt liên kết thì liên kết cũ hết hiệu lực.</p>
                        <div class="tp-share">
                            <input type="text" id="tpShareUrl" readonly aria-label="Liên kết chia sẻ" value="{{ $t['shared'] ? route('planner.shared', ['token' => $t['shareToken']]) : '' }}" @if (!$t['shared']) hidden @endif>
                            <button type="button" id="tpShareBtn" class="tp-btn tp-btn--ghost">{{ $t['shared'] ? 'Tắt liên kết' : 'Bật liên kết chỉ xem' }}</button>
                        </div>
                    @endif
                </section>
            </div>

            <aside class="tp-col-side">
                <section class="tp-card" aria-labelledby="tpMapH">
                    <h2 id="tpMapH">Bản đồ</h2>
                    <label class="tp-small">Hiển thị
                        <select class="no-nice" id="tpMapDay" aria-label="Chọn ngày hiển thị trên bản đồ"></select>
                    </label>
                    <div class="tp-mapwrap">
                        <div id="tpMap" class="tp-map" role="application" aria-label="Bản đồ các điểm trong chuyến đi"></div>
                        <div id="tpPlaceCard" class="tp-pcard" role="dialog" aria-label="Chi tiết địa điểm" hidden></div>
                    </div>
                    <p class="tp-muted tp-small">Ghim đánh số theo thứ tự trong ngày, màu theo ngày. Bấm một ghim để xem chi tiết địa điểm. Bản đồ © OpenStreetMap hoặc Google, tùy cấu hình.</p>
                </section>
                @if (!empty($tourFit))
                    <section class="tp-card tp-tourfit" aria-labelledby="tpTourH">
                        <h2 id="tpTourH">Tour Travela hợp chuyến này</h2>
                        <p class="tp-muted tp-small">Xếp theo độ khớp với ngày đi, số ngày, số người và ngân sách của bạn. Điểm chỉ để xếp thứ tự.</p>
                        <ul class="tp-tours">
                            @foreach ($tourFit as $tf)
                                <li class="tp-tours__item">
                                    <a href="{{ $tf['url'] }}"><strong>{{ $tf['title'] }}</strong></a>
                                    <span class="tp-muted tp-small">{{ $tf['destName'] }} · {{ $tf['time'] }} · khởi hành {{ $tf['start'] }}</span>
                                    <span class="tp-small">Cho nhóm: {{ number_format($tf['forGroup'], 0, ',', '.') }}đ</span>
                                    <ul class="tp-why">
                                        @foreach ($tf['reasons'] as $r)<li class="tp-why--good">{{ $r }}</li>@endforeach
                                        @foreach ($tf['warns'] as $w)<li class="tp-why--warn">{{ $w }}</li>@endforeach
                                    </ul>
                                </li>
                            @endforeach
                        </ul>
                    </section>
                @endif
                <section class="tp-card" aria-labelledby="tpWarnH">
                    <h2 id="tpWarnH">Cần chú ý</h2>
                    <div id="tpWarn"></div>
                </section>
            </aside>
        </div>
    </div>
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

<script type="application/json" id="tpData">{!! json_encode($client, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) !!}</script>
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
<script src="{{ asset('clients/assets/js/planner-geo.js') }}?v=2"></script>
<script src="{{ asset('clients/assets/js/planner-map.js') }}?v=2"></script>
<script src="{{ asset('clients/assets/js/planner.js') }}?v=9" defer></script>

@include('clients.blocks.footer')
