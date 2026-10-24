{{-- Header dùng chung (Travel theme v2). Trang chủ: @include('clients.blocks.site_header'); trang con: @include('clients.blocks.site_header', ['solid' => true]). Thay cho khối <header class="travel-modern-header"> cũ.
     Giữ nguyên: route, logic đăng nhập/avatar, form tìm kiếm giọng nói (#voice-search, name="keyword"). --}}
<header class="tv-header {{ !empty($solid) ? 'is-solid is-scrolled' : '' }}" id="tvHeader">
    <div class="tv-header__in">

        <a href="{{ route('home') }}" class="tv-logo px-brand" aria-label="Trang chủ {{ config('site.name') }}">
            {{-- Logo chữ + biểu tượng sóng/mặt trời (dùng màu theo trạng thái header). Khi có logo riêng: thay khối này bằng thẻ <img>. --}}
            <svg class="px-brand__mark" viewBox="0 0 40 40" aria-hidden="true">
                <defs>
                    <linearGradient id="pxBrandG" x1="0" y1="0" x2="1" y2="1">
                        <stop offset="0" stop-color="#25C7B5" />
                        <stop offset="1" stop-color="#0B9488" />
                    </linearGradient>
                </defs>
                <rect width="40" height="40" rx="13" fill="url(#pxBrandG)" />
                <circle cx="26" cy="14" r="5" fill="#DDAF32" />
                <path d="M6 25c4-4 7-4 11 0s7 4 11 0 5-3 6-2v5c-1-1-2-1-6 2s-7 3-11 0-7-4-11 0z" fill="#fff"
                    opacity=".95" />
                <path d="M6 32c4-4 7-4 11 0s7 4 11 0 5-3 6-2v3c-1-1-2-1-6 2s-7 3-11 0-7-4-11 0z" fill="#fff"
                    opacity=".55" />
            </svg>
            <span class="px-brand__name">{{ config('site.name', 'Travel') }}</span>
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

            <div class="tv-dd">
                <a href="{{ route('destination') }}"
                    class="{{ Request::is('diem-den*') || Request::is('am-thuc*') || Request::is('le-hoi*') || Request::is('lich-trinh*') || Request::is('dang-vao-mua*') || Request::is('nen-di-thang-nao*') ? 'is-active' : '' }}">
                    Khám phá <i class="fas fa-chevron-down"></i>
                </a>
                <div class="tv-dd__menu">
                    <a href="{{ route('destination') }}">Điểm đến</a>
                    <a href="{{ route('foods.index') }}">Ẩm thực</a>
                    <a href="{{ route('events.index') }}">Lễ hội</a>
                    <a href="{{ route('itineraries.index') }}">Lịch trình</a>
                    <a href="{{ route('seasons.now') }}">Đang vào mùa</a>
                    <a href="{{ route('seasons.matrix') }}">Nên đi tháng nào</a>
                </div>
            </div>
            @php $guideMenu = app(\App\Services\Content\GuideCatalog::class)->menu(); @endphp
            <div class="tv-dd tv-mega">
                <a href="{{ route('guides.index') }}" class="{{ Request::is('cam-nang*') ? 'is-active' : '' }}">
                    Cẩm nang <i class="fas fa-chevron-down"></i>
                </a>
                @if (count($guideMenu))
                    <div class="tv-mega__panel">
                        <div class="tv-mega__card">
                            <div class="tv-mega__cols">
                                @foreach ($guideMenu as $grp)
                                    <div>
                                        <p class="tv-mega__h">{{ $grp['name'] }}</p>
                                        @foreach ($grp['items'] as $it)
                                            <a class="tv-mega__item" href="{{ $it['url'] }}">
                                                <i class="bx {{ $it['icon'] }}" aria-hidden="true"></i>
                                                <span><b>{{ $it['title'] }}</b><small>{{ $it['desc'] }}</small></span>
                                            </a>
                                        @endforeach
                                    </div>
                                @endforeach
                            </div>
                            <a class="tv-mega__all" href="{{ route('guides.index') }}">Xem toàn bộ cẩm nang →</a>
                        </div>
                    </div>
                @endif
            </div>
            <a href="{{ route('ask.index') }}" class="{{ Request::is('hoi-dap*') ? 'is-active' : '' }}">Hỏi đáp</a>
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
                        <a href="{{ route('journal') }}"><i class="bx bx-book-open"></i><span>Sổ tay du
                                hành</span></a>
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
