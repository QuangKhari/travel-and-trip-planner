<!DOCTYPE html>
<html lang="vi">

<head>
    <meta http-equiv="Content-Type" content="text/html; charset=UTF-8">
    <meta charset="utf-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <title>Đăng nhập quản trị - Travela</title>

    <!-- Bootstrap -->
    <link href="{{ asset('admin/vendors/bootstrap/dist/css/bootstrap.min.css') }}" rel="stylesheet">
    <!-- Font Awesome -->
    <link href="{{ asset('admin/vendors/font-awesome/css/font-awesome.min.css') }}" rel="stylesheet">
    <!-- NProgress -->
    <link href="{{ asset('admin/vendors/nprogress/nprogress.css') }}" rel="stylesheet">
    <!-- Animate.css -->
    <link href="{{ asset('admin/vendors/animate.css/animate.min.css') }}" rel="stylesheet">

    <!-- Custom Theme Style -->
    <link href="{{ asset('admin/build/css/custom.min.css') }}" rel="stylesheet">
    <!-- Import CSS for Toastr -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/toastr.js/latest/toastr.min.css" />
    {{-- Custom css by DevDien  --}}
    <link href="{{ asset('admin/assets/css/custom-css.css') }}" rel="stylesheet" />

    <style>
        html, body {
            height: 100%;
            margin: 0;
        }

        body.login {
            display: flex;
            align-items: center;
            justify-content: center;
            background: linear-gradient(135deg, #1b2635 0%, #263445 50%, #1b2635 100%);
            font-family: 'Segoe UI', Arial, sans-serif;
            overflow: hidden;
            position: relative;
        }

        /* các vòng tròn trang trí mờ phía sau */
        body.login::before,
        body.login::after {
            content: '';
            position: absolute;
            border-radius: 50%;
            background: rgba(46, 204, 113, 0.08);
        }

        body.login::before {
            width: 500px;
            height: 500px;
            top: -150px;
            left: -150px;
        }

        body.login::after {
            width: 400px;
            height: 400px;
            bottom: -120px;
            right: -120px;
            background: rgba(46, 204, 113, 0.06);
        }

        .login-admin-card {
            position: relative;
            z-index: 2;
            width: 100%;
            max-width: 380px;
            background: #ffffff;
            border-radius: 14px;
            padding: 40px 35px 35px 35px;
            box-shadow: 0 20px 50px rgba(0, 0, 0, 0.35);
        }

        .login-admin-brand {
            text-align: center;
            margin-bottom: 28px;
        }

        .login-admin-brand .brand-icon {
            width: 62px;
            height: 62px;
            border-radius: 50%;
            background: linear-gradient(135deg, #2ecc71, #27ae60);
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 14px auto;
            box-shadow: 0 8px 20px rgba(46, 204, 113, 0.35);
        }

        .login-admin-brand .brand-icon i {
            color: #ffffff;
            font-size: 26px;
        }

        .login-admin-brand h1 {
            font-size: 22px;
            font-weight: 700;
            color: #263445;
            margin: 0 0 4px 0;
        }

        .login-admin-brand p {
            font-size: 13px;
            color: #9aa5b1;
            margin: 0;
        }

        .login-admin-form .form-group-custom {
            position: relative;
            margin-bottom: 18px;
        }

        .login-admin-form .form-group-custom i {
            position: absolute;
            left: 14px;
            top: 50%;
            transform: translateY(-50%);
            color: #9aa5b1;
            font-size: 15px;
        }

        .login-admin-form .form-control {
            height: 46px;
            padding-left: 42px;
            border-radius: 8px;
            border: 1px solid #e3e7ed;
            background-color: #f8f9fb;
            font-size: 14px;
            box-shadow: none;
            transition: all 0.2s ease;
        }

        .login-admin-form .form-control:focus {
            border-color: #2ecc71;
            background-color: #ffffff;
            box-shadow: 0 0 0 3px rgba(46, 204, 113, 0.12);
        }

        .btn-login-admin {
            width: 100%;
            height: 46px;
            border: none;
            border-radius: 8px;
            background: linear-gradient(135deg, #2ecc71, #27ae60);
            color: #ffffff;
            font-size: 15px;
            font-weight: 600;
            letter-spacing: 0.3px;
            margin-top: 6px;
            transition: all 0.2s ease;
        }

        .btn-login-admin:hover {
            filter: brightness(1.05);
            transform: translateY(-1px);
            box-shadow: 0 8px 18px rgba(46, 204, 113, 0.3);
            color: #ffffff;
        }

        .login-admin-back {
            text-align: center;
            margin-top: 22px;
        }

        .login-admin-back a {
            font-size: 13px;
            color: #9aa5b1;
            text-decoration: none;
        }

        .login-admin-back a:hover {
            color: #2ecc71;
            text-decoration: none;
        }
    </style>
</head>

<body class="login">

    <div class="login-admin-card">
        <div class="login-admin-brand">
            <div class="brand-icon">
                <i class="fa fa-paw"></i>
            </div>
            <h1>Travela Admin</h1>
            <p>Đăng nhập vào hệ thống quản trị</p>
        </div>

        <form action="{{ route('admin.login-account') }}" method="POST" id="formLoginAdmin" class="login-admin-form">
            @csrf

            <div class="form-group-custom">
                <i class="fa fa-user"></i>
                <input type="text" class="form-control" name="username" id="username"
                    placeholder="Tài khoản" required />
            </div>

            <div class="form-group-custom">
                <i class="fa fa-lock"></i>
                <input type="password" class="form-control" name="password" id="password"
                    placeholder="Mật khẩu" required />
            </div>

            <button class="btn-login-admin" type="submit">Đăng nhập</button>
        </form>

        <div class="login-admin-back">
            <a href="{{ route('login') }}"><i class="fa fa-arrow-left"></i> Quay lại trang người dùng</a>
        </div>
    </div>

    @include('admin.blocks.footer')
</body>

</html>