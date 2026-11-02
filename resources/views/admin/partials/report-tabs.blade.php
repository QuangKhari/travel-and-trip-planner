{{-- Hai trang báo cáo nội dung dùng chung một thanh tab để admin hiểu đây là MỘT báo cáo hai góc nhìn. --}}
<nav class="ad-tabs" aria-label="Báo cáo nội dung">
    <a href="{{ route('admin.content.overview') }}" class="{{ request()->routeIs('admin.content.overview') ? 'is-on' : '' }}">Số bài và độ phủ</a>
    <a href="{{ route('admin.content.quality') }}" class="{{ request()->routeIs('admin.content.quality*') ? 'is-on' : '' }}">Điểm và lỗi dữ liệu</a>
</nav>
