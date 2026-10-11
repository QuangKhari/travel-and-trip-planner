<!DOCTYPE html>
<html lang="vi">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title', config('site.name', 'Travel'))</title>
</head>

<body style="margin:0;padding:0;background:#F1F5F9;font-family:Arial,Helvetica,sans-serif;color:#0F172A;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#F1F5F9;padding:28px 12px;">
        <tr>
            <td align="center">
                <table role="presentation" width="100%" cellpadding="0" cellspacing="0"
                    style="max-width:560px;background:#ffffff;border-radius:14px;overflow:hidden;">
                    <tr>
                        <td style="padding:22px 32px;border-bottom:3px solid #0D9488;">
                            <span
                                style="font-size:22px;font-weight:700;letter-spacing:.04em;color:#0F172A;">{{ strtoupper(config('site.name', 'Travel')) }}</span>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:30px 32px 10px;font-size:15px;line-height:1.7;">
                            @yield('content')
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:6px 32px 28px;font-size:12px;line-height:1.6;color:#64748B;">
                            @yield('footnote')
                            <p style="margin:18px 0 0;border-top:1px solid #E2E8F0;padding-top:14px;">
                                Email này được gửi tự động từ {{ config('site.name', 'Travel') }}. Vui lòng không chia
                                sẻ liên kết trong email cho người khác.
                            </p>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>

</html>
