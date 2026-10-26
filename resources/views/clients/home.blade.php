@include('clients.blocks.header_home')
{{--
  Mạch trang chủ: GỢI CẢM HỨNG TRƯỚC, bán tour sau.
  Khối nào chưa có dữ liệu thì tự ẩn (xem home_rail.blade.php).
--}}
@if (count($postcards) >= 3)
    @include('clients.blocks.hero-postcards')
@else
    @include('clients.blocks.banner_home')
@endif

@include('clients.blocks.home_strips', ['which' => 'season'])
@include('clients.blocks.interest_section')
@include('clients.blocks.home_strips', ['which' => 'foods'])
@include('clients.blocks.home_strips', ['which' => 'experiences'])
@include('clients.blocks.home_strips', ['which' => 'events'])
@include('clients.blocks.home_strips', ['which' => 'itineraries'])
@include('clients.blocks.home_strips', ['which' => 'guides'])

{{-- Cuối trang: chuyển cảm hứng thành hành động. #hpl là đích của "bưu ảnh trắng" trong hero. --}}
<section class="hq hq--end" aria-label="Bắt đầu chuyến đi">
    <div class="container">
        <div class="hq-card">
            @if (count($postcards) >= 3)
                <div class="hq-tabs" role="tablist" aria-label="Cách bắt đầu chuyến đi">
                    <button type="button" class="hq-tab" role="tab" id="hq-tab-plan" aria-controls="hq-pane-plan"
                        aria-selected="true">Gợi ý lịch trình</button>
                    <button type="button" class="hq-tab" role="tab" id="hq-tab-tour" aria-controls="hq-pane-tour"
                        aria-selected="false" tabindex="-1">Tìm tour theo ngày</button>
                </div>
                <div class="hq-pane" id="hq-pane-plan" role="tabpanel" aria-labelledby="hq-tab-plan">
                    @include('clients.blocks.home_planner')
                </div>
                <div class="hq-pane" id="hq-pane-tour" role="tabpanel" aria-labelledby="hq-tab-tour" hidden>
                    @include('clients.blocks.home_search_strip')
                </div>
            @else
                @include('clients.blocks.home_planner')
            @endif
        </div>
    </div>
</section>
<script>
    (function() {
        var tabs = [].slice.call(document.querySelectorAll('.hq-tab'));
        if (!tabs.length) return;

        function show(t) {
            tabs.forEach(function(x) {
                var on = x === t;
                x.setAttribute('aria-selected', on ? 'true' : 'false');
                x.tabIndex = on ? 0 : -1;
                document.getElementById(x.getAttribute('aria-controls')).hidden = !on;
            });
        }
        tabs.forEach(function(t, i) {
            t.addEventListener('click', function() {
                show(t);
            });
            t.addEventListener('keydown', function(e) {
                var to = e.key === 'ArrowRight' ? (i + 1) % tabs.length : e.key === 'ArrowLeft' ? (
                    i - 1 + tabs.length) % tabs.length : -1;
                if (to < 0) return;
                e.preventDefault();
                show(tabs[to]);
                tabs[to].focus();
            });
        });
        addEventListener('hashchange', function() {
            if (location.hash === '#hpl') show(tabs[0]);
        });
    })();
</script>
@include('clients.blocks.tour_featured')

@include('clients.blocks.footer_home')
