<!DOCTYPE html>
<html lang="vi">

<head>
    <meta charset="utf-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="robots" content="noindex, nofollow">
    <link rel="icon" href="{{ asset('favicon.ico') }}" type="image/ico" />

    <title>{{ $title ?? 'Quản trị' }} · {{ config('site.name', 'Travela') }} Admin</title>

    {{-- Chỉ nạp thư viện admin thật sự dùng (bỏ Flot, JQVMap, Skycons, DataTables... của Gentelella) --}}
    <link href="{{ asset('admin/vendors/bootstrap/dist/css/bootstrap.min.css') }}" rel="stylesheet">
    <link href="{{ asset('admin/vendors/font-awesome/css/font-awesome.min.css') }}" rel="stylesheet">
    <link href="{{ asset('admin/vendors/nprogress/nprogress.css') }}" rel="stylesheet">
    <link href="{{ asset('admin/build/css/custom.min.css') }}" rel="stylesheet">
    <link href="{{ asset('admin/assets/css/jquery.datetimepicker.min.css') }}" rel="stylesheet">
    <link href="{{ asset('vendor/flasher/toastr.min.css') }}" rel="stylesheet">
    <link href="{{ asset('admin/vendors/dropzone/dist/min/dropzone.min.css') }}" rel="stylesheet" />
    <link href="{{ asset('admin/assets/css/custom-css.css') }}" rel="stylesheet" />

    <link href="{{ asset('admin/assets/css/admin-app.css') }}?v={{ @filemtime(public_path('admin/assets/css/admin-app.css')) }}" rel="stylesheet" />
    @stack('styles')
    <script>
        // Nhớ trạng thái thu gọn menu trước khi vẽ trang (không bị nháy)
        try { if (localStorage.getItem('ad.nav') === 'sm' && window.innerWidth > 991) document.documentElement.classList.add('ad-pre-sm'); } catch (e) {}
    </script>
</head>

<body class="nav-md">
