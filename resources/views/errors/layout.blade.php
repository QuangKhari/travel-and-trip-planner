<!DOCTYPE html>
<html lang="vi">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('code') – {{ config('site.name', 'Travel') }}</title>
    <link rel="icon" href="{{ asset('clients/assets/images/logos/favicon.png') }}">
    <link href="https://fonts.googleapis.com/css2?family=Be+Vietnam+Pro:wght@400;500;600;700&display=swap"
        rel="stylesheet">
    <style>
        * {
            box-sizing: border-box;
            margin: 0
        }

        body {
            min-height: 100vh;
            display: grid;
            place-items: center;
            padding: 28px;
            font-family: 'Be Vietnam Pro', system-ui, sans-serif;
            color: #16324A;
            background: #FFFDF8
        }

        .box {
            max-width: 520px;
            text-align: center
        }

        .code {
            font-size: clamp(72px, 16vw, 128px);
            font-weight: 700;
            line-height: 1;
            letter-spacing: -.04em;
            color: #0E8F8A
        }

        h1 {
            font-size: 26px;
            font-weight: 700;
            margin: 14px 0 10px
        }

        p {
            color: #6B7F8F;
            line-height: 1.7;
            margin-bottom: 26px
        }

        .actions {
            display: flex;
            gap: 12px;
            justify-content: center;
            flex-wrap: wrap
        }

        a.btn {
            display: inline-block;
            padding: 12px 26px;
            border-radius: 12px;
            font-weight: 600;
            text-decoration: none;
            font-size: 15px
        }

        a.primary {
            background: #FF7A00;
            color: #fff
        }

        a.primary:hover {
            background: #E86E00
        }

        a.ghost {
            background: #fff;
            color: #16324A;
            box-shadow: inset 0 0 0 1.5px #E8EEF0
        }

        a.ghost:hover {
            box-shadow: inset 0 0 0 1.5px #0E8F8A;
            color: #0E8F8A
        }

        .brand {
            margin-bottom: 34px
        }

        .brand img {
            height: 36px
        }
    </style>
</head>

<body>
    <main class="box">
        <div class="brand"><a href="{{ url('/') }}"><img
                    src="{{ asset('clients/assets/images/logos/logo-two.png') }}"
                    alt="{{ config('site.name', 'Travel') }}"></a></div>
        <div class="code">@yield('code')</div>
        <h1>@yield('title')</h1>
        <p>@yield('message')</p>
        <div class="actions">
            <a class="btn primary" href="{{ url('/') }}">Về trang chủ</a>
            @hasSection('secondary')
                @yield('secondary')
            @else
                <a class="btn ghost" href="javascript:history.back()">Quay lại</a>
            @endif
        </div>
    </main>
</body>

</html>
