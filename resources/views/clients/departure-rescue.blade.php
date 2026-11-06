@include('clients.blocks.header')
<main style="max-width:850px;margin:45px auto;padding:24px"><h1>Đề xuất điều chỉnh chuyến đi</h1><p>Không có thay đổi nào được áp dụng chỉ vì bạn đã nhận thông báo.</p>
@forelse($items as $p)<article style="padding:22px;margin:20px 0;border:1px solid #91B2A1;border-radius:14px;background:#F8F7F2;color:#34443E">
<h2>{{ $p->title }}</h2><p>Booking {{ $p->bookingCode }} · Ngày gốc: {{ $p->departureDate }}</p><p><b>Trạng thái:</b> {{ $p->status }} · <b>Phản hồi:</b> {{ $p->decision }}</p>
@if($p->type==='cancel_review')<p style="padding:10px;background:#FFF6D6;border-radius:8px"><b>Đây là thông báo Travela có thể phải hủy khởi hành do chưa đủ khách.</b> Nếu hủy, bạn được hoàn 100% số tiền đã thanh toán. Việc bạn bấm "Đã hiểu" chỉ để Travela biết bạn đã đọc.</p>
@elseif(($p->declinePolicy ?? 'reject_all')==='refund_decliners')<p style="padding:10px;background:#FFF6D6;border-radius:8px"><b>Nếu bạn không đồng ý hoặc không trả lời trước hạn,</b> Travela sẽ hủy đơn của bạn và hoàn 100% số tiền đã thanh toán; các khách còn lại vẫn áp dụng phương án.</p>@endif
<p>{{ $p->description }}</p><p><b>Thay đổi:</b> {{ $p->changeSummary }}</p><p><b>Cam kết:</b> {{ $p->serviceGuarantee }}</p><p>Hạn phản hồi: {{ $p->responseDeadline }}</p>
@if($p->status==='pending' && $p->decision==='pending' && \Carbon\Carbon::parse($p->responseDeadline)->isFuture())
<div style="display:flex;gap:10px;flex-wrap:wrap"><form method="POST" action="{{ route('departure.rescue.respond',[$p->planId,$p->bookingId]) }}">@csrf<input type="hidden" name="decision" value="accepted"><button style="padding:12px;background:#426E60;color:white;border:0;border-radius:7px" type="submit">{{ $p->type==='cancel_review' ? 'Đã hiểu' : 'Tôi đồng ý' }}</button></form>
@if($p->type!=='cancel_review')<form method="POST" action="{{ route('departure.rescue.respond',[$p->planId,$p->bookingId]) }}">@csrf<input type="hidden" name="decision" value="declined"><button style="padding:12px;border:1px solid #426E60;border-radius:7px" type="submit">{{ ($p->declinePolicy ?? 'reject_all')==='refund_decliners' ? 'Không đồng ý — hủy đơn & hoàn 100%' : 'Không đồng ý' }}</button></form>@endif</div>
@endif
</article>@empty<p>Hiện không có đề xuất điều chỉnh chuyến đi.</p>@endforelse
</main>
@include('clients.blocks.footer')
