        @include('clients.partials.site-footer')

        @include('clients.blocks.chat_widget')
@include('clients.blocks.study_bar')
        <!-- footer area end -->

        </div>
        <!--End pagewrapper-->


        {{-- JS trang chủ: gộp theo nhóm, đúng thứ tự cũ (config/assets.php: home-a.js / home-b.js) --}}
        {!! \App\Support\AssetBundle::tags('home-a.js') !!}
        @include('clients.blocks.mobile_tabbar')
        @include('clients.blocks.trip_boot')
        <script src="{{ asset('clients/assets/js/tv3.js') }}?v=1" defer></script>
        {{-- home-b.js (tv-home -> travel-chat -> datetimepicker): tv-home phải chạy trước inspiration-hub.js (defer) để nhận sự kiện đổi cảm hứng đầu tiên --}}
        {!! \App\Support\AssetBundle::tags('home-b.js') !!}
        <script src="{{ asset('clients/assets/js/tv-tours-10.js') }}?v=2" defer></script>


        <script src="https://cdnjs.cloudflare.com/ajax/libs/toastr.js/latest/toastr.min.js"></script>


        <script src="{{ asset('clients/assets/js/nature-finish.js') }}?v=1" defer></script>
</body>

        </html>
