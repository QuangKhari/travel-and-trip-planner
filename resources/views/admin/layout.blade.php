{{-- Khung chung cho trang quản trị: @extends('admin.layout') rồi @section('content'). Trang cũ vẫn include header/sidebar/footer trực tiếp. --}}
@include('admin.blocks.header')
<div class="container body">
    <div class="main_container">
        @include('admin.blocks.sidebar')
        <div class="right_col" role="main">
            <div class="ad-wrap">
                @include('admin.partials.flash')
                @yield('content')
            </div>
        </div>
    </div>
</div>
@include('admin.blocks.footer')
