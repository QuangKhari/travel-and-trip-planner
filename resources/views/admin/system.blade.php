@include('admin.blocks.header')
<div class="container body">
    <div class="main_container">
        @include('admin.blocks.sidebar')

        <div class="right_col" role="main">
            @include('admin.partials.flash')
            <div class="ad-head">
                <div>
                    <h1>Bảo trì hệ thống</h1>
                    <p>Kiểm tra nhanh cấu hình và xóa cache khi nội dung công khai không cập nhật.</p>
                </div>
            </div>

            <div class="ad-grid ad-grid--21">
                <div class="ad-card">
                    <div class="ad-card__head"><h2>Kiểm tra hệ thống</h2></div>
                    <div class="ad-tablewrap">
                        <table class="ad-table">
                            <tbody>
                            @foreach ($checks as $c)
                                <tr>
                                    <td style="width:34%"><b>{{ $c['name'] }}</b></td>
                                    <td><span class="ad-badge ad-badge--{{ $c['state'] === 'ok' ? 'ok' : ($c['state'] === 'warn' ? 'warn' : 'bad') }}">{{ $c['value'] }}</span>
                                        @if ($c['hint'])<div class="ad-note" style="margin-top:4px">{{ $c['hint'] }}</div>@endif</td>
                                </tr>
                            @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>

                <div>
                    <div class="ad-card">
                        <div class="ad-card__head"><h2>Cache nội dung</h2></div>
                        <div class="ad-card__body">
                            <p class="ad-note">Xóa cache trang chủ, bưu ảnh, sitemap và tìm kiếm. Không ảnh hưởng dữ liệu.</p>
                            <form method="POST" action="{{ route('admin.system.flush') }}">@csrf
                                <button class="ad-btn ad-btn--primary"><i class="fa fa-refresh"></i> Xóa cache nội dung</button>
                            </form>
                        </div>
                    </div>
                    <div class="ad-card">
                        <div class="ad-card__head"><h2>Chỉ mục Hỏi đáp</h2></div>
                        <div class="ad-card__body">
                            <p class="ad-note">{{ $ragDirty ? 'Nội dung đã thay đổi sau lần dựng gần nhất.' : 'Chỉ mục đang khớp với nội dung.' }} Dựng lại có thể mất vài chục giây.</p>
                            <form method="POST" action="{{ route('admin.content.rag') }}">@csrf
                                <button class="ad-btn" data-confirm="Dựng lại chỉ mục Hỏi đáp bây giờ?"><i class="fa fa-database"></i> Dựng lại chỉ mục</button>
                            </form>
                        </div>
                    </div>
                    <div class="ad-card">
                        <div class="ad-card__head"><h2>Giao diện</h2></div>
                        <div class="ad-card__body">
                            <p class="ad-note">Dùng khi vừa sửa file .blade.php mà trang vẫn hiện bản cũ.</p>
                            <form method="POST" action="{{ route('admin.system.views') }}">@csrf
                                <button class="ad-btn"><i class="fa fa-paint-brush"></i> Xóa view đã biên dịch</button>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
@include('admin.blocks.footer')
