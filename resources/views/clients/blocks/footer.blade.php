{{-- Footer: nội dung nằm ở clients/partials/site-footer.blade.php (dùng chung với trang chủ). --}}
@include('clients.partials.site-footer')

@include('clients.blocks.chat_widget')

</div>
<!--End pagewrapper-->


<!-- Jquery -->
<script src="{{ asset('clients/assets/js/jquery-3.6.0.min.js') }}"></script>
<!-- Bootstrap -->
<script src="{{ asset('clients/assets/js/bootstrap.min.js') }}"></script>
<!-- Appear Js -->
<script src="{{ asset('clients/assets/js/appear.min.js') }}"></script>
<!-- Slick -->
<script src="{{ asset('clients/assets/js/slick.min.js') }}"></script>
<!-- Magnific Popup -->
<script src="{{ asset('clients/assets/js/jquery.magnific-popup.min.js') }}"></script>
<!-- Nice Select -->
<script src="{{ asset('clients/assets/js/jquery.nice-select.min.js') }}"></script>
<!-- Image Loader -->
<script src="{{ asset('clients/assets/js/imagesloaded.pkgd.min.js') }}"></script>
<!-- Skillbar -->
<script src="{{ asset('clients/assets/js/skill.bars.jquery.min.js') }}"></script>
<!-- Isotope -->
<script src="{{ asset('clients/assets/js/isotope.pkgd.min.js') }}"></script>
<!--  AOS Animation -->
<script src="{{ asset('clients/assets/js/aos.js') }}"></script>
<!-- Custom script -->
<script src="{{ asset('clients/assets/js/script.js') }}"></script>

<script src="{{ asset('clients/assets/js/custom-js.js') }}"></script>
<script src="{{ asset('clients/assets/js/travel-theme.js') }}?v=4"></script>
<script src="{{ asset('clients/assets/js/sea-theme.js') }}?v=1"></script>
<script src="{{ asset('clients/assets/js/client-ui-polish.js') }}?v=4"></script>
<script src="{{ asset('clients/assets/js/travel-chat.js') }}?v=1"></script>

<script src="{{ asset('clients/assets/js/jquery.datetimepicker.full.min.js') }}"></script>

<script src="https://cdnjs.cloudflare.com/ajax/libs/toastr.js/latest/toastr.min.js"></script>
<script src="{{ asset('clients/assets/js/ux-pass.js') }}?v=3"></script>
<script src="{{ asset('clients/assets/js/gx-discover.js') }}?v=1" defer></script>
@include('clients.blocks.mobile_tabbar')
@include('clients.blocks.trip_boot')

</body>

</html>
