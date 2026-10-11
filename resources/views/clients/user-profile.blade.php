@include('clients.blocks.header')

<main class="tv-profile user-profile">
    <div class="container">
        <header class="tv-pagehead">
            <h1>Tài khoản của tôi</h1>
            <p>Cập nhật thông tin liên hệ để lần đặt tour sau nhanh hơn.</p>
        </header>

        <div class="tv-profile__grid">

            {{-- Cột trái: ảnh đại diện. GIỮ NGUYÊN .card-body + input#avatar + input.__token + input.label_avatar (custom-js.js dùng) --}}
            <aside>
                <div class="tv-panel text-center">
                    <div class="card-body">
                        <img id="avatarPreview" class="img-account-profile tv-avatar"
                            src="{{ \App\Support\Avatar::url($user->avatar) }}" alt="Ảnh đại diện">
                        <h2 class="tv-profile__name">{{ $user->fullName }}</h2>
                        <p class="tv-muted-sm">{{ $user->email }}</p>

                        <input type="file" name="avatar" id="avatar" style="display: none" accept="image/*">
                        <input type="hidden" name="_token" value="{{ csrf_token() }}" class="__token">
                        <input type="hidden" name="" value="{{ route('change-avatar') }}" class="label_avatar">
                        <label for="avatar" class="tv-btn-ghost tv-btn-block"><i class="fal fa-camera"></i> Đổi ảnh
                            đại diện</label>
                        <p class="tv-muted-sm tv-mt">JPG hoặc PNG, tối đa 5 MB</p>
                    </div>
                </div>

                <div class="tv-panel tv-panel--links">
                    <a href="{{ route('my-tours') }}"><i class="fal fa-suitcase-rolling"></i> Tour đã đặt <i
                            class="fal fa-chevron-right"></i></a>
                    <button type="button" id="update_password_profile"><i class="fal fa-lock"></i> Đổi mật khẩu <i
                            class="fal fa-chevron-down"></i></button>
                </div>
            </aside>

            {{-- Cột phải: thông tin + đổi mật khẩu. GIỮ NGUYÊN id/class của form --}}
            <div>
                <section class="tv-panel">
                    <h2 class="tv-panel__title">Thông tin cá nhân</h2>
                    <form action="{{ route('update-user-profile') }}" method="POST" name="updateUser"
                        class="updateUser" novalidate>
                        @csrf
                        <div class="tv-fieldrow">
                            <label for="inputFullName">Họ và tên</label>
                            <input id="inputFullName" type="text" placeholder="Họ và tên"
                                value="{{ $user->fullName }}" required>
                        </div>
                        <div class="tv-fieldrow">
                            <label for="inputLocation">Địa chỉ</label>
                            <input id="inputLocation" type="text" placeholder="Địa chỉ" value="{{ $user->address }}"
                                required>
                        </div>
                        <div class="tv-fieldrow">
                            <label for="inputEmailAddress">Email</label>
                            <input id="inputEmailAddress" type="email" placeholder="Email" value="{{ $user->email }}"
                                required>
                            <button type="button" class="tv-btn-ghost tv-btn-sm" id="btnResendEmailVerification"
                                style="display: none;">Gửi lại email xác thực</button>
                            <small class="tv-muted-sm" id="emailVerificationMessage" style="display: none;">
                                Email mới chưa được xác thực. Vui lòng kiểm tra hộp thư.
                            </small>
                        </div>
                        <div class="tv-fieldrow tv-fieldrow--half">
                            <label for="inputPhone">Số điện thoại</label>
                            <input id="inputPhone" type="number" placeholder="Số điện thoại"
                                value="{{ $user->phoneNumber }}" required>
                        </div>
                        <button class="tv-btn-solid" type="submit" id="update_profile">Lưu thông tin</button>
                    </form>
                </section>

                <section class="tv-panel" id="card_change_password">
                    <h2 class="tv-panel__title">Đổi mật khẩu</h2>
                    <div class="invalid-feedback" id="validate_password"></div>
                    <form action="{{ route('change-password') }}" method="post" class="change_password_profile"
                        novalidate>
                        @csrf
                        <div class="tv-fieldrow">
                            <label for="inputOldPass">Mật khẩu hiện tại</label>
                            <input id="inputOldPass" type="password" autocomplete="current-password"
                                placeholder="Nhập mật khẩu cũ" required>
                        </div>
                        <div class="tv-fieldrow">
                            <label for="inputNewPass">Mật khẩu mới</label>
                            <input id="inputNewPass" type="password" autocomplete="new-password"
                                placeholder="Nhập mật khẩu mới" required>
                        </div>
                        <button class="tv-btn-solid" type="submit">Cập nhật mật khẩu</button>
                    </form>
                </section>
            </div>
        </div>
    </div>
</main>

@include('clients.blocks.footer')
