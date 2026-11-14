@include('clients.blocks.header')
{{-- /chuyen-di/tham-gia/{token}: xác nhận tham gia chuyến đi từ liên kết mời. Biến: $trip (null nếu liên kết hết hiệu lực), $owner, $token, $already --}}
<main class="tv-mytours tv-join">
    <div class="container">
        <div class="tv-join__card">
            @if (!$trip)
                <i class="fal fa-link tv-join__ic" aria-hidden="true"></i>
                <h1>Liên kết mời không còn hiệu lực</h1>
                <p>Chủ chuyến đi có thể đã tắt hoặc tạo lại liên kết. Bạn nhờ họ gửi liên kết mới nhé.</p>
                <a class="tv-btn-ghost" href="{{ route('planner') }}">Về Chuyến đi của tôi</a>
            @else
                <i class="fal fa-users tv-join__ic" aria-hidden="true"></i>
                <p class="tv-join__eyebrow">Lời mời cùng lên kế hoạch</p>
                <h1>{{ $trip->title }}</h1>
                <p class="tv-join__meta">
                    {{ $owner ?: 'Một người dùng Travela' }} mời bạn
                    · {{ (int) $trip->days }} ngày
                    @if ($trip->startDate) · từ {{ \Carbon\Carbon::parse($trip->startDate)->format('d/m/Y') }} @endif
                </p>
                <p>Bạn sẽ vào nhóm với quyền <b>{{ ($trip->joinRole ?? 'edit') === 'edit' ? 'chỉnh sửa' : 'chỉ xem' }}</b>: thấy chuyến trong “Chuyến đi của tôi”{{ ($trip->joinRole ?? 'edit') === 'edit' ? ', thêm, sửa lịch và ghi khoản chi chung' : '' }}. Bạn tự rời nhóm được bất cứ lúc nào.</p>
                @if ($already)
                    <a class="tv-btn-solid" href="{{ route('planner.edit', ['id' => $trip->tripId]) }}">Bạn đã ở trong chuyến — mở chuyến đi</a>
                @else
                    <form method="POST" action="{{ route('planner.join.store', ['token' => $token]) }}">@csrf
                        <button class="tv-btn-solid" type="submit">Tham gia chuyến đi</button>
                        <a class="tv-btn-ghost" href="{{ route('planner') }}">Để sau</a>
                    </form>
                @endif
            @endif
        </div>
    </div>
</main>
@include('clients.blocks.footer')
