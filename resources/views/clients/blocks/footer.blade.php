{{-- Footer: nội dung nằm ở clients/partials/site-footer.blade.php (dùng chung với trang chủ). --}}
@include('clients.partials.site-footer')

@include('clients.blocks.chat_widget')
@include('clients.blocks.study_bar')

</div>
<!--End pagewrapper-->


{{-- JS dùng chung: gộp theo nhóm, chạy đồng bộ đúng thứ tự cũ (config/assets.php). toastr giữ đúng chỗ (ux-pass.js dùng). --}}
{!! \App\Support\AssetBundle::tags('site-a.js') !!}
<script src="https://cdnjs.cloudflare.com/ajax/libs/toastr.js/latest/toastr.min.js"></script>
{!! \App\Support\AssetBundle::tags('site-b.js') !!}
<script src="{{ asset('clients/assets/js/tv3.js') }}?v=1" defer></script>
<script src="{{ asset('clients/assets/js/gx-discover.js') }}?v=1" defer></script>
<script>window.TV_COMPARE_URL = @json(route('tours.compare'));</script>
<script src="{{ asset('clients/assets/js/tour-compare.js') }}?v=1" defer></script>
@include('clients.blocks.mobile_tabbar')
@include('clients.blocks.trip_boot')

<script src="{{ asset('clients/assets/js/nature-finish.js') }}?v=1" defer></script>
{{-- JS riêng của từng trang: @push('page_scripts') trong view (sau jQuery và các gói chung) --}}
@stack('page_scripts')
</body>

</html>
