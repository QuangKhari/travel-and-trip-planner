{{-- Header trang chủ (Travel theme v2). Thay cho khối <header class="travel-modern-header"> cũ.
     Giữ nguyên: route, logic đăng nhập/avatar, form tìm kiếm giọng nói (#voice-search, name="keyword"). --}}
<header class="tv-header" id="tvHeader">
    <div class="tv-header__in">

        <a href="{{ route('home') }}" class="tv-logo" aria-label="Trang chủ">
            {{-- Logo tạm giữ nguyên ảnh cũ (bản trắng). Khi có logo mới: thay file logo.png cùng tên. --}}
            <img src="{{ asset('clients/assets/images/logos/logo.png') }}" alt="Travel">
        </a>

        <nav class="tv-nav" id="tvNav" aria-label="Menu chính">
            <a href="{{ route('home') }}" class="{{ Request::url() == route('home') ? 'is-active' : '' }}">Trang chủ</a>
            <a href="{{ route('about') }}" class="{{ Request::url() == route('about') ? 'is-active' : '' }}">Giới
                thiệu</a>

            <div class="tv-dd">
                <a href="{{ route('tours') }}"
                    class="{{ Request::is('tours') || Request::is('tour-detail/*') || Request::is('team') ? 'is-active' : '' }}">
                    Tours <i class="fas fa-chevron-down"></i>
                </a>
                <div class="tv-dd__menu">
                    <a href="{{ route('tours') }}">Tour</a>
                    <a href="{{ route('team') }}">Hướng dẫn viên</a>
                </div>
            </div>

            <a href="{{ route('destination') }}"
                class="{{ Request::url() == route('destination') ? 'is-active' : '' }}">Điểm đến</a>
            <a href="{{ route('blogs') }}" class="{{ Request::url() == route('blogs') ? 'is-active' : '' }}">Blog</a>
            <a href="{{ route('contact') }}" class="{{ Request::url() == route('contact') ? 'is-active' : '' }}">Liên
                hệ</a>
        </nav>

        <div class="tv-actions">
            <div class="tv-hsearch" id="tvHsearch">
                <form action="{{ route('search-voice-text') }}" method="GET" class="tv-hsearch__form">
                    <input type="text" name="keyword" placeholder="Bạn muốn đi đâu?" autocomplete="off" required>
                    <button type="button" class="tv-icon-btn" id="voice-search" aria-label="Tìm bằng giọng nói">
                        <i class="fa fa-microphone"></i>
                    </button>
                    <button type="submit" class="tv-icon-btn" aria-label="Tìm kiếm"><i
                            class="far fa-search"></i></button>
                </form>
                <button type="button" class="tv-icon-btn tv-hsearch__toggle" aria-label="Mở ô tìm kiếm">
                    <i class="far fa-search"></i>
                </button>
            </div>

            <a href="{{ route('tours') }}" class="tv-book"><span>Khám phá tour</span> <i
                    class="fal fa-arrow-right"></i></a>

            <div class="tv-user" id="tvUser">
                <button type="button" class="tv-user__btn" aria-label="Tài khoản">
                    @if (session()->has('username') && session('avatar'))
                        @php $avatar = session()->get('avatar', 'user_avatar.jpg'); @endphp
                        <img src="{{ \App\Support\Avatar::url($avatar) }}" alt="User">
                    @else
                        <i class="bx bxs-user"></i>
                    @endif
                </button>

                <div class="tv-user__menu">
                    @if (session()->has('username'))
                        <div class="tv-user__name">{{ session()->get('username') }}</div>
                        <a href="{{ route('user-profile') }}"><i class="bx bx-user"></i><span>Thông tin cá
                                nhân</span></a>
                        <a href="{{ route('my-tours') }}"><i class="bx bx-map"></i><span>Tour đã đặt</span></a>
                        <form action="{{ route('logout') }}" method="POST">
                            @csrf
                            <button type="submit"><i class="bx bx-log-out"></i><span>Đăng xuất</span></button>
                        </form>
                    @else
                        <a href="{{ route('login') }}"><i class="bx bx-log-in"></i><span>Đăng nhập</span></a>
                    @endif
                </div>
            </div>

            <button type="button" class="tv-burger" id="tvBurger" aria-label="Mở menu"
                aria-expanded="false"><span></span></button>
        </div>
    </div>
</header>
