@include('clients.blocks.header')
@php
    // Biến từ DepartureRescueResponseController::index: $items (phương án điều chỉnh gửi tới các đơn của khách)
    $planStatus = ['draft' => 'Đang soạn', 'pending' => 'Đang chờ khách phản hồi', 'ready' => 'Đủ phản hồi, chờ Travela áp dụng', 'applied' => 'Đã áp dụng',
        'rejected' => 'Không áp dụng', 'expired' => 'Hết hạn phản hồi', 'cancelled' => 'Travela đã hủy phương án', 'manual_review' => 'Travela đang xem xét'];
    $decision = ['pending' => ['Chưa trả lời', 'is-wait'], 'accepted' => ['Bạn đã đồng ý', 'is-go'], 'declined' => ['Bạn không đồng ý', 'is-cancel'], 'timeout' => ['Quá hạn trả lời', 'is-cancel']];
    $typeLabel = ['partial' => 'Ghép đoàn một phần hành trình', 'date_change' => 'Đổi ngày khởi hành', 'keep' => 'Giữ lịch, điều chỉnh dịch vụ', 'cancel_review' => 'Có thể hủy khởi hành'];
    $fmt = fn($d) => $d ? \Carbon\Carbon::parse($d)->format('H:i d/m/Y') : '—';
@endphp

<main class="tv-mytours tv-rescue">
    <div class="container">
        <header class="tv-pagehead">
            <h1>Đề xuất điều chỉnh chuyến đi</h1>
            <p>Khi đoàn chưa đủ khách, Travela có thể đề xuất ghép đoàn, đổi ngày hoặc hủy và hoàn tiền. Không có thay đổi nào được áp dụng chỉ vì bạn đã nhận thông báo.</p>
        </header>

        @forelse ($items as $p)
            @php
                [$dLabel, $dClass] = $decision[$p->decision] ?? [$p->decision, ''];
                $open = $p->status === 'pending' && $p->decision === 'pending' && \Carbon\Carbon::parse($p->responseDeadline)->isFuture();
                $refundDecliners = ($p->declinePolicy ?? 'reject_all') === 'refund_decliners';
            @endphp
            <article class="tv-rescue__card">
                <div class="tv-rescue__top">
                    <span class="tv-status-pill {{ $dClass }}">{{ $dLabel }}</span>
                    <span class="tv-rescue__type">{{ $typeLabel[$p->type] ?? $p->type }}</span>
                </div>
                <h2>{{ $p->title }}</h2>
                <p class="tv-rescue__meta">Đơn <b>{{ $p->bookingCode }}</b> · Ngày khởi hành gốc {{ $p->departureDate ? \Carbon\Carbon::parse($p->departureDate)->format('d/m/Y') : '—' }}
                    · {{ $planStatus[$p->status] ?? $p->status }}</p>

                @if ($p->type === 'cancel_review')
                    <p class="tv-rescue__note"><b>Travela có thể phải hủy khởi hành do chưa đủ khách.</b> Nếu hủy, bạn được hoàn 100% số tiền đã thanh toán. Bấm “Đã hiểu” chỉ để Travela biết bạn đã đọc.</p>
                @elseif ($refundDecliners)
                    <p class="tv-rescue__note"><b>Nếu bạn không đồng ý hoặc không trả lời trước hạn,</b> Travela hủy đơn của bạn và hoàn 100% số tiền đã thanh toán; các khách còn lại vẫn áp dụng phương án.</p>
                @endif

                <dl class="tv-rescue__dl">
                    @if ($p->description)<div><dt>Lý do</dt><dd>{{ $p->description }}</dd></div>@endif
                    @if ($p->changeSummary)<div><dt>Thay đổi</dt><dd>{{ $p->changeSummary }}</dd></div>@endif
                    @if ($p->serviceGuarantee)<div><dt>Travela cam kết</dt><dd>{{ $p->serviceGuarantee }}</dd></div>@endif
                    <div><dt>Hạn phản hồi</dt><dd>{{ $fmt($p->responseDeadline) }}</dd></div>
                </dl>

                @if ($open)
                    <div class="tv-rescue__acts">
                        <form method="POST" action="{{ route('departure.rescue.respond', [$p->planId, $p->bookingId]) }}">@csrf
                            <input type="hidden" name="decision" value="accepted">
                            <button class="tv-btn-solid" type="submit">{{ $p->type === 'cancel_review' ? 'Đã hiểu' : 'Tôi đồng ý' }}</button>
                        </form>
                        @if ($p->type !== 'cancel_review')
                            <form method="POST" action="{{ route('departure.rescue.respond', [$p->planId, $p->bookingId]) }}" onsubmit="return confirm(this.dataset.ask)" data-ask="{{ $refundDecliners ? 'Không đồng ý: đơn sẽ bị hủy và hoàn 100%. Tiếp tục?' : 'Gửi phản hồi không đồng ý?' }}">@csrf
                                <input type="hidden" name="decision" value="declined">
                                <button class="tv-btn-ghost" type="submit">{{ $refundDecliners ? 'Không đồng ý — hủy đơn & hoàn 100%' : 'Không đồng ý' }}</button>
                            </form>
                        @endif
                    </div>
                @endif
            </article>
        @empty
            <div class="tv-empty">
                <i class="fal fa-check-circle" aria-hidden="true"></i>
                <h3>Không có đề xuất nào</h3>
                <p>Các chuyến của bạn đang giữ nguyên lịch. Khi cần điều chỉnh, Travela sẽ gửi email và hiện ở đây.</p>
                <a class="tv-btn-ghost" href="{{ route('my-tours') }}">Xem tour đã đặt</a>
            </div>
        @endforelse
    </div>
</main>
@include('clients.blocks.footer')
