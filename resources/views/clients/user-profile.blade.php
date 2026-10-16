@include('clients.blocks.header')
@php
    // Biến từ UserProfileController::index: $user, $pendingEmail, $stats
    $fields = [
        'fullName' => (string) $user->fullName,
        'email' => (string) $user->email,
        'phone' => (string) $user->phoneNumber,
        'address' => (string) $user->address,
    ];
    $filled = collect($fields)->filter(fn($v) => trim($v) !== '')->count();
    $percent = (int) round(($filled / count($fields)) * 100);
    $missing = [];
    if (trim($fields['phone']) === '') {
        $missing[] = 'số điện thoại';
    }
    if (trim($fields['address']) === '') {
        $missing[] = 'địa chỉ';
    }
    $total = (int) ($stats->total ?? 0);
@endphp

<main class="pf" id="pfPage">
    <div class="container">

        <header class="pf-head">
            <h1>Tài khoản của tôi</h1>
            <p>Thông tin chính xác giúp lần đặt tour sau chỉ còn vài bước.</p>
        </header>

        <div class="pf-layout">

            {{-- ================= CỘT TRÁI: ảnh, mức hoàn thiện, đơn đặt ================= --}}
            <aside class="pf-side">
                <section class="pf-card pf-me">
                    <div class="pf-avatar">
                        <img id="pfAvatarImg" class="img-account-profile"
                            src="{{ \App\Support\Avatar::url($user->avatar) }}"
                            alt="Ảnh đại diện của {{ $user->fullName }}" width="120" height="120">
                        <span class="pf-avatar__busy" id="pfAvatarBusy" hidden><i class="fal fa-spinner-third fa-spin"
                                aria-hidden="true"></i></span>
                    </div>
                    <h2 class="pf-me__name" id="pfName">{{ $user->fullName }}</h2>
                    <p class="pf-me__mail">{{ $user->email }}</p>

                    {{-- Ô chọn file ẩn bằng CSS (vẫn focus được bằng bàn phím), nhãn là nút bấm --}}
                    <input type="file" id="pfAvatar" class="pf-file" accept="image/jpeg,image/png,image/webp"
                        data-url="{{ route('change-avatar') }}" aria-describedby="pfAvatarHint">
                    <label for="pfAvatar" class="pf-btn pf-btn--ghost pf-btn--block"><i class="fal fa-camera"
                            aria-hidden="true"></i> Đổi ảnh đại diện</label>
                    <p class="pf-hint" id="pfAvatarHint">JPG, PNG hoặc WebP, tối đa 5 MB.</p>
                    <p class="pf-err" id="pfAvatarErr" role="alert"></p>
                </section>

                <section class="pf-card" aria-labelledby="pfDoneH">
                    <h2 id="pfDoneH" class="pf-card__t">Hoàn thiện hồ sơ</h2>
                    <div class="pf-meter" role="progressbar" aria-valuemin="0" aria-valuemax="100"
                        aria-valuenow="{{ $percent }}" aria-labelledby="pfDoneH">
                        <i style="width: {{ $percent }}%"></i>
                    </div>
                    <p class="pf-meter__txt"><b id="pfPercent">{{ $percent }}%</b>
                        <span id="pfMissing">
                            @if ($missing)
                                Thêm {{ implode(' và ', $missing) }} để đặt tour nhanh hơn.
                            @else
                                Hồ sơ của bạn đã đầy đủ.
                            @endif
                        </span>
                    </p>
                </section>

                <section class="pf-card" aria-labelledby="pfBookH">
                    <h2 id="pfBookH" class="pf-card__t">Đơn đặt tour</h2>
                    @if ($total > 0)
                        <ul class="pf-stats">
                            <li><b>{{ $total }}</b><span>Tổng đơn</span></li>
                            <li><b>{{ (int) $stats->waiting }}</b><span>Chờ xác nhận</span></li>
                            <li><b>{{ (int) $stats->confirmed }}</b><span>Đã xác nhận</span></li>
                        </ul>
                        <a class="pf-btn pf-btn--ghost pf-btn--block" href="{{ route('my-tours') }}"><i
                                class="fal fa-suitcase-rolling" aria-hidden="true"></i> Xem tour đã đặt</a>
                    @else
                        <p class="pf-hint">Bạn chưa đặt tour nào. Hãy chọn một chuyến đi đầu tiên nhé.</p>
                        <a class="pf-btn pf-btn--solid pf-btn--block" href="{{ route('tours') }}">Khám phá tour</a>
                    @endif
                </section>
            </aside>

            {{-- ================= CỘT PHẢI ================= --}}
            <div class="pf-main">

                {{-- Email mới chờ xác minh (hiện lại cả khi tải lại trang) --}}
                <div class="pf-alert pf-alert--warn" id="pfPending" role="status"
                    @if (!$pendingEmail) hidden @endif>
                    <i class="fal fa-envelope-open-text" aria-hidden="true"></i>
                    <div>
                        <b>Email mới chưa được xác minh</b>
                        <p>Chúng tôi đã gửi thư tới <b id="pfPendingMail">{{ $pendingEmail }}</b>. Bấm liên kết trong
                            thư để hoàn tất. Trong lúc chờ, bạn vẫn đăng nhập bằng <b>{{ $user->email }}</b>.</p>
                        <button type="button" class="pf-link" id="pfResend"
                            data-url="{{ route('email.change.resend') }}">Gửi
                            lại thư xác minh</button>
                    </div>
                </div>

                <section class="pf-card" id="thong-tin" aria-labelledby="pfInfoH">
                    <h2 id="pfInfoH" class="pf-card__h">Thông tin cá nhân</h2>

                    <form id="pfForm" action="{{ route('update-user-profile') }}" method="POST" novalidate>
                        @csrf
                        <div class="pf-grid">
                            <div class="pf-field pf-field--full">
                                <label for="pfFullName">Họ và tên <em aria-hidden="true">*</em></label>
                                <input id="pfFullName" name="fullName" type="text" maxlength="50" autocomplete="name"
                                    value="{{ $user->fullName }}" required aria-describedby="pfErr-fullName">
                                <p class="pf-err" id="pfErr-fullName"></p>
                            </div>

                            <div class="pf-field">
                                <label for="pfPhone">Số điện thoại</label>
                                <input id="pfPhone" name="phone" type="tel" inputmode="tel" maxlength="15"
                                    autocomplete="tel" value="{{ $user->phoneNumber }}" placeholder="0901234567"
                                    aria-describedby="pfHint-phone pfErr-phone">
                                <p class="pf-hint" id="pfHint-phone">Dùng để nhân viên gọi xác nhận đơn.</p>
                                <p class="pf-err" id="pfErr-phone"></p>
                            </div>

                            <div class="pf-field">
                                <label for="pfEmail">Email <em aria-hidden="true">*</em></label>
                                <input id="pfEmail" name="email" type="email" maxlength="255"
                                    autocomplete="email" value="{{ $user->email }}" required
                                    aria-describedby="pfHint-email pfErr-email">
                                <p class="pf-hint" id="pfHint-email">Đổi email sẽ cần xác minh qua thư gửi tới email
                                    mới.
                                </p>
                                <p class="pf-err" id="pfErr-email"></p>
                            </div>

                            <div class="pf-field pf-field--full">
                                <label for="pfAddress">Địa chỉ</label>
                                <input id="pfAddress" name="address" type="text" maxlength="255"
                                    autocomplete="street-address" value="{{ $user->address }}"
                                    placeholder="Số nhà, đường, quận/huyện, tỉnh/thành"
                                    aria-describedby="pfErr-address">
                                <p class="pf-err" id="pfErr-address"></p>
                            </div>
                        </div>

                        <div class="pf-actions">
                            <button type="submit" class="pf-btn pf-btn--solid" id="pfSave" disabled>Lưu thông
                                tin</button>
                            <span class="pf-state" id="pfState" role="status" aria-live="polite">Chưa có thay
                                đổi</span>
                        </div>
                    </form>
                </section>

                <section class="pf-card" id="bao-mat" aria-labelledby="pfPassH">
                    <h2 id="pfPassH" class="pf-card__h">Đổi mật khẩu</h2>

                    <form id="pfPassForm" action="{{ route('change-password') }}" method="POST" novalidate>
                        @csrf
                        <div class="pf-grid">
                            <div class="pf-field pf-field--full">
                                <label for="pfOld">Mật khẩu hiện tại</label>
                                <div class="pf-pass">
                                    <input id="pfOld" name="oldPass" type="password"
                                        autocomplete="current-password" aria-describedby="pfErr-oldPass">
                                    <button type="button" class="pf-eye" data-pass-toggle="#pfOld"
                                        aria-label="Hiện mật khẩu" aria-pressed="false"><i class="fal fa-eye"
                                            aria-hidden="true"></i></button>
                                </div>
                                <p class="pf-err" id="pfErr-oldPass"></p>
                            </div>

                            <div class="pf-field">
                                <label for="pfNew">Mật khẩu mới</label>
                                <div class="pf-pass">
                                    <input id="pfNew" name="newPass" type="password" autocomplete="new-password"
                                        aria-describedby="pfHint-new pfErr-newPass">
                                    <button type="button" class="pf-eye" data-pass-toggle="#pfNew"
                                        aria-label="Hiện mật khẩu" aria-pressed="false"><i class="fal fa-eye"
                                            aria-hidden="true"></i></button>
                                </div>
                                <div class="pf-strength" id="pfStrength" data-level="0" aria-hidden="true">
                                    <i></i><i></i><i></i><i></i></div>
                                <p class="pf-hint" id="pfHint-new">Ít nhất 8 ký tự. Nên có chữ hoa, chữ thường và số.
                                </p>
                                <p class="pf-err" id="pfErr-newPass"></p>
                            </div>

                            <div class="pf-field">
                                <label for="pfConfirm">Nhập lại mật khẩu mới</label>
                                <div class="pf-pass">
                                    <input id="pfConfirm" name="confirmPass" type="password"
                                        autocomplete="new-password" aria-describedby="pfErr-confirmPass">
                                    <button type="button" class="pf-eye" data-pass-toggle="#pfConfirm"
                                        aria-label="Hiện mật khẩu" aria-pressed="false"><i class="fal fa-eye"
                                            aria-hidden="true"></i></button>
                                </div>
                                <p class="pf-err" id="pfErr-confirmPass"></p>
                            </div>
                        </div>

                        <div class="pf-actions">
                            <button type="submit" class="pf-btn pf-btn--solid" id="pfPassSave">Cập nhật mật
                                khẩu</button>
                            <span class="pf-state" id="pfPassState" role="status" aria-live="polite"></span>
                        </div>
                    </form>
                </section>
            </div>
        </div>
    </div>
</main>

<input type="hidden" id="pfToken" value="{{ csrf_token() }}">

@include('clients.blocks.footer')
