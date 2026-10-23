{{-- E3 — Thanh tab đáy trên điện thoại (dưới 600px). Mặc định ẨN; travela-trip.js gắn class "has-tabbar" lên <body>
     (trừ trang đã có thanh đáy riêng .td-mbar / .bk-bar) thì CSS mới hiện. Nút giữa "Kế hoạch" mở tờ trượt khay chuyến đi. --}}
@php
    $tbLoggedIn = session()->has('username');
    $tbExplore = request()->routeIs('home', 'destination', 'destination.show', 'destinations.list', 'foods.*', 'itineraries.*', 'seasons.*', 'guides.*', 'explore', 'site.search', 'data.sources');
    $tbTours = request()->routeIs('tours', 'tour-detail', 'filter-tours', 'search');
    $tbEvents = request()->routeIs('events.*');
    $tbAccount = request()->routeIs('user-profile', 'journal', 'my-tours', 'tour-booked', 'login', 'register.form');
@endphp
<nav class="gx-tabbar" aria-label="Điều hướng nhanh">
    <a class="gx-tab {{ $tbExplore ? 'is-active' : '' }}" href="{{ route('home') }}"
        @if ($tbExplore) aria-current="page" @endif>
        <i class="fal fa-compass" aria-hidden="true"></i><span>Khám phá</span>
    </a>
    <a class="gx-tab {{ $tbTours ? 'is-active' : '' }}" href="{{ route('tours') }}"
        @if ($tbTours) aria-current="page" @endif>
        <i class="fal fa-ticket-alt" aria-hidden="true"></i><span>Tour</span>
    </a>
    <button type="button" class="gx-tab gx-tab--plan" data-tray-open aria-haspopup="dialog" aria-expanded="false">
        <span class="gx-tab__bubble">
            <i class="fal fa-route" aria-hidden="true"></i>
            <b class="gx-tab-count" data-tray-count hidden>0</b>
        </span>
        <span>Kế hoạch</span>
    </button>
    <a class="gx-tab {{ $tbEvents ? 'is-active' : '' }}" href="{{ route('events.index') }}"
        @if ($tbEvents) aria-current="page" @endif>
        <i class="fal fa-calendar-alt" aria-hidden="true"></i><span>Lễ hội</span>
    </a>
    <a class="gx-tab {{ $tbAccount ? 'is-active' : '' }}"
        href="{{ $tbLoggedIn ? route('user-profile') : route('login') }}"
        @if ($tbAccount) aria-current="page" @endif>
        <i class="fal fa-user" aria-hidden="true"></i><span>{{ $tbLoggedIn ? 'Tài khoản' : 'Đăng nhập' }}</span>
    </a>
</nav>
