{{-- Hai trang báo cáo nội dung dùng chung một thanh tab để admin hiểu đây là MỘT báo cáo hai góc nhìn. --}}
<ul class="nav nav-tabs" style="margin-bottom:14px">
    <li class="{{ request()->routeIs('admin.content.overview') ? 'active' : '' }}"><a href="{{ route('admin.content.overview') }}">Số bài và độ phủ</a></li>
    <li class="{{ request()->routeIs('admin.content.quality*') ? 'active' : '' }}"><a href="{{ route('admin.content.quality') }}">Điểm và lỗi dữ liệu</a></li>
</ul>
