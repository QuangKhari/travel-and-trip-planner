@include('clients.blocks.header')
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css">
<link rel="stylesheet" href="{{ asset('clients/assets/css/planner.css') }}?v=21">

{{-- /len-ke-hoach/{id}. Biến: $payload (TripPlanner::payload), $client (dữ liệu cho planner.js), $role, $members, $tourFit.
     Bố cục "Timeline + Bản đồ" (tham khảo Wanderlog: lịch theo ngày cạnh bản đồ; Roadtrippers: bản đồ lớn, tuyến nối các điểm, thêm điểm dừng theo danh mục).
     Toàn bộ dải ngày, timeline, sức khỏe chuyến đi, chi phí và tổng quan do planner.js vẽ từ #tpData; Blade chỉ dựng khung. --}}
@php
    $t = $payload['trip'];
@endphp

<main class="tp tp--edit tpx" id="tpRoot">
    {{-- ---------------- Thanh công cụ trên cùng (kiểu Wanderlog: đã lưu · hoàn tác · làm lại ... chia sẻ) ---------------- --}}
    <div class="tpx-top" role="toolbar" aria-label="Công cụ chuyến đi">
        <a class="tpx-back" href="{{ route('planner') }}"><i class="fas fa-arrow-left" aria-hidden="true"></i> Chuyến đi của tôi</a>
        <div class="tpx-head__act">
            <span id="tpMsg" class="tpx-save" role="status" aria-live="polite"></span>
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
                    <button type="button" class="tpx-menu__i" role="menuitem" id="tpPrint"><i class="fas fa-print" aria-hidden="true"></i><span>In / lưu thành PDF</span></button>
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
                    </div>
                </div>

                <section class="tpx-health" id="tpHealth" aria-labelledby="tpWarnH">
                    <h2 id="tpWarnH" class="tpx-health__h"></h2>
                    <div id="tpWarn"></div>
                </section>

                <div id="tpBulk" class="tp-bulk" hidden></div>
                <div id="tpRouteNote" class="tp-banner" hidden role="status"></div>
                <div id="tpDaysBox" class="tpx-timeline"></div>
                <p id="tpTotals" class="tp-totals tpx-totals" role="status"></p>
                <p class="tp-muted tp-small tpx-hint">
                    Giờ trong lịch được xếp tự động từ thứ tự, thời lượng ở lại và quãng đường (bắt đầu 08:00). Bấm <b>Sửa</b> ở một mục để đặt giờ cố định hoặc đổi thời lượng.
                    @if ($role !== 'view') Kéo biểu tượng <i class="fas fa-grip-vertical" aria-hidden="true"></i> để đổi thứ tự hoặc chuyển ngày. Mọi thay đổi tự lưu sau vài giây. @endif
                </p>

                @if (!empty($tourFit))
                    <details class="tpx-more tp-tourfit" open>
                        <summary><i class="fas fa-suitcase-rolling" aria-hidden="true"></i> Tour Travela hợp chuyến này ({{ count($tourFit) }})</summary>
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

    {{-- ================= Thanh thao tác trên điện thoại ================= --}}
    <nav class="tpx-mob" aria-label="Thao tác nhanh">
        <button type="button" class="tpx-mob__b is-on" data-mob="plan"><i class="fas fa-stream" aria-hidden="true"></i><span>Lịch trình</span></button>
        <button type="button" class="tpx-mob__b" data-mob="map"><i class="fas fa-map-marked-alt" aria-hidden="true"></i><span>Bản đồ</span></button>
        @if ($role !== 'view')<button type="button" class="tpx-mob__b tpx-mob__add" data-mob="add" aria-label="Thêm vào chuyến đi"><i class="fas fa-plus" aria-hidden="true"></i></button>@endif
        <button type="button" class="tpx-mob__b" data-mob="cost"><i class="fas fa-wallet" aria-hidden="true"></i><span>Chi phí</span></button>
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

<script type="application/json" id="tpData">{!! json_encode($client, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) !!}</script>
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
{{-- planner-geo (tuyến) + planner-schedule (xếp giờ): lõi tính xem trước, tham số từ cfg.rules (PlannerRules); planner-api: gọi máy chủ; planner.js: giao diện --}}
<script src="{{ asset('clients/assets/js/planner-geo.js') }}?v=3"></script>
<script src="{{ asset('clients/assets/js/planner-schedule.js') }}?v=4"></script>
<script src="{{ asset('clients/assets/js/planner-api.js') }}?v=2"></script>
<script src="{{ asset('clients/assets/js/planner-save.js') }}?v=1"></script>
<script src="{{ asset('clients/assets/js/planner-resolve.js') }}?v=1"></script>
<script src="{{ asset('clients/assets/js/planner-map.js') }}?v=5"></script>
<script src="{{ asset('clients/assets/js/planner.js') }}?v=22" defer></script>

@include('clients.blocks.footer')
