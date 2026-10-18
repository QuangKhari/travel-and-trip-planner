{{-- Trang lỗi dùng chung (404, 403, 419, 429, 500, 503). File này TỰ ĐỨNG (không nạp CSS của site)
     để vẫn hiển thị đúng khi chính site bị lỗi. Chỉ dùng url()/config(), không truy vấn CSDL.
     Mỗi mã lỗi có minh họa màu nước và nút hành động phù hợp riêng. --}}
@php
    $code = trim($__env->yieldContent('code'));
    $wc = asset('clients/assets/images/watercolor');
    $img = asset('clients/assets/images');
    $sitePhone = config('site.phone');
    $siteName = config('site.name', 'Travel');

    // Minh họa theo mã lỗi (đều là SVG có sẵn trong dự án)
    $scene =
        [
            '404' => $img . '/illustrations/compass.svg',
            '403' => $img . '/wc-bamboo.svg',
            '419' => $img . '/wc-paper-plane.svg',
            '429' => $img . '/wc-boat.svg',
            '500' => $img . '/wc-mountain-line.svg',
            '503' => $img . '/wc-rice-field.svg',
        ][$code] ??
        $img . '/wc-travel-scene.svg';

    // Nút chính theo từng tình huống: người dùng cần làm gì tiếp theo thì nút đó nổi bật nhất
    $reload = in_array($code, ['419', '500', '503'], true);
    $wait = $code === '429';
@endphp
<!DOCTYPE html>
<html lang="vi">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>@yield('title') ({{ $code }}) – {{ $siteName }}</title>
    <link rel="icon" href="{{ asset('clients/assets/images/logos/favicon.png') }}">
    <link href="https://fonts.googleapis.com/css2?family=Be+Vietnam+Pro:wght@400;500;600;700;800&display=swap"
        rel="stylesheet">
    <style>
        *,
        *::before,
        *::after {
            box-sizing: border-box
        }

        [hidden] {
            display: none !important
        }

        html,
        body {
            margin: 0
        }

        body {
            min-height: 100vh;
            font-family: 'Be Vietnam Pro', system-ui, sans-serif;
            color: #102f3a;
            background-color: #f8fbf9;
            background-image: url("{{ $wc }}/wc-paper.svg");
            background-size: 420px;
            position: relative;
            isolation: isolate;
            overflow-x: hidden;
            display: grid;
            place-items: center;
            padding: 32px 20px
        }

        /* ----- trang trí màu nước (nằm dưới nội dung, không bắt chuột) ----- */
        .wc {
            position: fixed;
            z-index: -1;
            pointer-events: none;
            user-select: none
        }

        .wc--teal {
            top: -170px;
            right: -190px;
            width: min(820px, 90vw);
            opacity: .24
        }

        .wc--sand {
            bottom: -210px;
            left: -200px;
            width: min(760px, 90vw);
            opacity: .22
        }

        .wc--leaf-l {
            left: -70px;
            bottom: -30px;
            width: 230px;
            opacity: .3
        }

        .wc--leaf-r {
            right: -60px;
            top: 40%;
            width: 200px;
            opacity: .24;
            transform: scaleX(-1) rotate(8deg)
        }

        .wc--birds {
            top: 11%;
            left: 9%;
            width: 120px;
            opacity: .75
        }

        .box {
            width: min(560px, 100%);
            text-align: center
        }

        .brand {
            margin-bottom: 20px
        }

        .brand img {
            height: 38px
        }

        .scene {
            display: block;
            width: min(260px, 62vw);
            max-height: 190px;
            object-fit: contain;
            margin: 0 auto 4px
        }

        .code {
            position: relative;
            display: inline-block;
            font-size: clamp(76px, 18vw, 132px);
            font-weight: 800;
            line-height: 1;
            letter-spacing: -.04em;
            color: #0b9488;
            padding: 0 6px
        }

        /* nét cọ màu nước dưới mã lỗi */
        .code::after {
            content: "";
            position: absolute;
            left: -8%;
            right: -8%;
            bottom: -6px;
            height: 22px;
            background: url("{{ $wc }}/wc-brush.svg") center/100% 100% no-repeat;
            opacity: .8
        }

        h1 {
            font-size: clamp(22px, 4vw, 28px);
            font-weight: 800;
            margin: 22px 0 10px
        }

        .msg {
            color: #475569;
            line-height: 1.7;
            margin: 0 auto 24px;
            max-width: 460px
        }

        .actions {
            display: flex;
            gap: 12px;
            justify-content: center;
            flex-wrap: wrap
        }

        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            min-height: 48px;
            padding: 0 26px;
            border-radius: 14px;
            border: 1.5px solid transparent;
            font: 700 15px 'Be Vietnam Pro', system-ui, sans-serif;
            text-decoration: none;
            cursor: pointer;
            transition: transform .15s, box-shadow .2s, background .2s
        }

        .btn:focus-visible,
        .links a:focus-visible,
        .search button:focus-visible,
        .search input:focus-visible {
            outline: 3px solid #ddaf32;
            outline-offset: 2px
        }

        .primary {
            background: #f4c95d;
            color: #102f3a;
            box-shadow: 0 8px 20px rgba(201, 154, 34, .28)
        }

        .primary:hover {
            background: #e8b93e;
            transform: translateY(-1px)
        }

        .ghost {
            background: #fff;
            color: #102f3a;
            border-color: #cfe3df
        }

        .ghost:hover {
            border-color: #0b9488;
            color: #086b66
        }

        .btn[disabled] {
            opacity: .65;
            cursor: not-allowed;
            transform: none
        }

        /* ô tìm tour: chỉ hiện ở 404 để người lạc đường có lối đi ngay */
        .search {
            display: flex;
            gap: 8px;
            max-width: 420px;
            margin: 26px auto 0;
            padding: 6px;
            background: rgba(255, 255, 255, .92);
            border: 1.5px solid #cfe3df;
            border-radius: 16px
        }

        .search input {
            flex: 1;
            min-width: 0;
            border: 0;
            background: transparent;
            padding: 0 12px;
            font: 500 16px 'Be Vietnam Pro', system-ui, sans-serif;
            color: #102f3a
        }

        .search input:focus-visible {
            outline-offset: -2px;
            border-radius: 10px
        }

        .search button {
            border: 0;
            border-radius: 12px;
            padding: 0 18px;
            height: 44px;
            background: #0b9488;
            color: #fff;
            font: 700 14px 'Be Vietnam Pro', system-ui, sans-serif;
            cursor: pointer
        }

        .links {
            margin-top: 22px;
            font-size: 14px;
            color: #64748b
        }

        .links a {
            display: inline-block;
            margin: 4px 6px;
            padding: 6px 14px;
            border-radius: 999px;
            background: rgba(255, 255, 255, .8);
            border: 1px solid #dbe8e5;
            color: #086b66;
            font-weight: 600;
            text-decoration: none
        }

        .links a:hover {
            background: #f0fdfa;
            border-color: #0b9488
        }

        .help {
            margin-top: 18px;
            font-size: 14px;
            color: #64748b
        }

        .help a {
            color: #086b66;
            font-weight: 600
        }

        .sr {
            position: absolute;
            width: 1px;
            height: 1px;
            overflow: hidden;
            clip: rect(0 0 0 0)
        }

        @media (max-width:520px) {
            .wc--leaf-r {
                display: none
            }

            .wc--birds {
                left: auto;
                right: 6%
            }

            .btn {
                width: 100%
            }
        }

        @media (prefers-reduced-motion:reduce) {
            * {
                transition: none !important
            }
        }
    </style>
</head>

<body>
    <img class="wc wc--teal" src="{{ $wc }}/wc-wash-teal.svg" alt="" aria-hidden="true">
    <img class="wc wc--sand" src="{{ $wc }}/wc-wash-sand.svg" alt="" aria-hidden="true">
    <img class="wc wc--leaf-l" src="{{ $wc }}/wc-leaf-a.svg" alt="" aria-hidden="true">
    <img class="wc wc--leaf-r" src="{{ $wc }}/wc-leaf-b.svg" alt="" aria-hidden="true">
    <img class="wc wc--birds" src="{{ $wc }}/wc-birds.svg" alt="" aria-hidden="true">

    <main class="box" id="main">
        <div class="brand"><a href="{{ url('/') }}"><img
                    src="{{ asset('clients/assets/images/logos/logo-two.png') }}"
                    alt="{{ $siteName }} – về trang chủ"></a>
        </div>

        <img class="scene" src="{{ $scene }}" alt="" aria-hidden="true">
        <div class="code" aria-hidden="true">{{ $code }}</div>
        <h1>@yield('title')<span class="sr"> (mã lỗi {{ $code }})</span></h1>
        <p class="msg">@yield('message')</p>

        <div class="actions">
            @if ($wait)
                {{-- 429: tự mở lại sau khi đếm ngược xong, người dùng không phải đoán bao lâu --}}
                <button type="button" class="btn primary" id="retry" data-wait="20" disabled>Thử lại sau <span
                        id="count">20</span> giây</button>
            @elseif ($reload)
                <button type="button" class="btn primary" onclick="location.reload()">Tải lại trang</button>
            @else
                <a class="btn primary" href="{{ url('/') }}">Về trang chủ</a>
            @endif

            @hasSection('secondary')
                @yield('secondary')
            @elseif ($reload || $wait)
                <a class="btn ghost" href="{{ url('/') }}">Về trang chủ</a>
            @else
                <a class="btn ghost" href="{{ url('/tours') }}">Xem các tour</a>
            @endif

            {{-- Quay lại chỉ khi có trang trước; mở trực tiếp trang lỗi thì không có nút "chết" --}}
            <a class="btn ghost" href="{{ url('/') }}" id="back" hidden>Quay lại</a>
        </div>

        @if ($code === '404')
            <form class="search" action="{{ url('/tours') }}" method="GET" role="search">
                <label class="sr" for="errQ">Tìm tour</label>
                <input id="errQ" type="search" name="keyword" placeholder="Tìm tour: Đà Nẵng, Phú Quốc…"
                    maxlength="100">
                <button type="submit">Tìm</button>
            </form>
        @endif

        @if (in_array($code, ['404', '403'], true))
            <nav class="links" aria-label="Lối tắt">
                <a href="{{ url('/tours') }}">Tour</a>
                <a href="{{ url('/destination') }}">Điểm đến</a>
                <a href="{{ url('/blogs') }}">Blog</a>
                <a href="{{ url('/contact') }}">Liên hệ</a>
            </nav>
        @endif

        @if (in_array($code, ['500', '503', '403'], true))
            <p class="help">Cần hỗ trợ ngay?
                @if ($sitePhone)
                    Gọi <a href="tel:{{ preg_replace('/\s+/', '', $sitePhone) }}">{{ $sitePhone }}</a> hoặc
                @endif
                <a href="{{ url('/contact') }}">gửi tin nhắn cho chúng tôi</a>.
            </p>
        @endif
    </main>

    <script>
        (function() {
            // Nút "Quay lại" chỉ hiện khi thật sự có trang trước thuộc cùng site
            var back = document.getElementById('back');
            if (back && history.length > 1 && document.referrer && document.referrer.indexOf(location.origin) === 0) {
                back.hidden = false;
                back.addEventListener('click', function(e) {
                    e.preventDefault();
                    history.back();
                });
            }
            // 429: đếm ngược rồi cho phép thử lại
            var retry = document.getElementById('retry');
            if (retry) {
                var n = parseInt(retry.getAttribute('data-wait'), 10) || 20,
                    c = document.getElementById('count');
                var t = setInterval(function() {
                    n--;
                    c.textContent = n;
                    if (n <= 0) {
                        clearInterval(t);
                        retry.disabled = false;
                        retry.textContent = 'Thử lại ngay';
                        retry.addEventListener('click', function() {
                            location.reload();
                        });
                    }
                }, 1000);
            }
        })();
    </script>
</body>

</html>
