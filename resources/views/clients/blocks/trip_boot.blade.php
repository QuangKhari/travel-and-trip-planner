{{-- Cấu hình cho travela-trip.js (khay chuyến đi + đánh dấu). Nạp trước hero-postcards.js. --}}
<script>
    window.TRAVELA_TRIP = {!! json_encode(
        [
            'loggedIn' => session()->has('userId'),
            'csrf' => csrf_token(),
            'syncUrl' => route('trip-tray.sync'),
            'markUrl' => route('marks.toggle'),
            'loginUrl' => route('login'),
            'plannerUrl' => config('postcards.planner_url') ? url(config('postcards.planner_url')) : null,
        ],
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT,
    ) !!};
</script>
<script src="{{ asset('clients/assets/js/travela-trip.js') }}?v=3"></script>
