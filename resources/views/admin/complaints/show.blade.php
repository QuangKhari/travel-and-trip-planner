@extends('admin.layout')
@section('content')
    <div class="ad-head"><div><h1>{{ $c->code }}: {{ $c->subject }}</h1>
        <p>{{ $cats[$c->category] ?? $c->category }} · đơn <a href="{{ route('admin.booking-detail', ['id' => $c->bookingId]) }}">{{ $c->bookingCode }}</a> · {{ $c->title }} · <b>{{ $statuses[$c->status] ?? $c->status }}</b>@if ($c->claimedAmount) · khách đề nghị {{ number_format($c->claimedAmount, 0, ',', '.') }} đ @endif</p></div>
        <a class="ad-btn" href="{{ route('admin.complaints') }}">← Danh sách</a></div>
    <div style="display:grid;grid-template-columns:minmax(0,2fr) minmax(0,1fr);gap:20px;align-items:start">
        <div>
            <section class="ad-card" style="margin-bottom:20px"><div class="ad-card__head"><h2>Trao đổi</h2></div><div class="ad-card__body">
                @foreach ($msgs as $m)
                    <div style="margin-bottom:12px;padding:10px 12px;border-radius:8px;background:{{ $m->authorType === 'staff' ? '#F4F8F6' : '#FFF' }};border:1px solid #E4ECE8">
                        <b>{{ $m->authorType === 'staff' ? 'Travela' : 'Khách' }}</b> <small>{{ date('H:i d/m/Y', strtotime($m->createdAt)) }}</small><div style="white-space:pre-line">{{ $m->body }}</div></div>
                @endforeach
                @if ($c->status !== 'closed')
                    <form method="POST" action="{{ route('admin.complaints.reply', $c->complaintId) }}">@csrf
                        <textarea name="body" rows="3" required minlength="3" maxlength="4000" placeholder="Phản hồi khách (gửi kèm email)" style="width:100%"></textarea>
                        <button class="ad-btn ad-btn--primary" type="submit" style="margin-top:8px">Gửi phản hồi</button></form>
                @endif
            </div></section>
        </div>
        <div>
            <section class="ad-card" style="margin-bottom:20px"><div class="ad-card__head"><h2>Trạng thái</h2></div><div class="ad-card__body">
                @if ($c->resolution)<p><b>Kết quả:</b> {{ $c->resolution }}</p>@endif
                @if (!empty($flow[$c->status]))
                    <form method="POST" action="{{ route('admin.complaints.status', $c->complaintId) }}" class="ad-form">@csrf
                        <div class="ad-fld"><label>Chuyển sang</label><select name="status">@foreach ($flow[$c->status] as $s)<option value="{{ $s }}">{{ $statuses[$s] }}</option>@endforeach</select></div>
                        <div class="ad-fld"><label>Kết quả / lý do (bắt buộc khi giải quyết hoặc từ chối)</label><textarea name="resolution" rows="3" maxlength="3000"></textarea></div>
                        <button class="ad-btn ad-btn--primary" type="submit">Cập nhật</button></form>
                @else<p>Khiếu nại đã đóng.</p>@endif
            </div></section>
            @if ($c->status !== 'closed')
                <section class="ad-card"><div class="ad-card__head"><h2>Bồi hoàn</h2></div><div class="ad-card__body">
                    <p>Khách đã thanh toán {{ number_format($paid, 0, ',', '.') }} đ (bồi hoàn tối đa bằng số này). Sổ bồi hoàn tách khỏi “Hoàn tiền” của đơn.</p>
                    @if ($c->compensationStatus === 'paid')
                        <p>Đã chi {{ number_format($c->compensationAmount, 0, ',', '.') }} đ · {{ $c->compensationMethod }} {{ $c->compensationRef }}</p>
                    @else
                        <form method="POST" action="{{ route('admin.complaints.compensation', $c->complaintId) }}" class="ad-form">@csrf
                            <div class="ad-fld"><label>Số tiền bồi hoàn (đ)</label><input type="number" name="amount" min="0" max="{{ $paid }}" value="{{ $c->compensationAmount }}" required></div>
                            <div class="ad-fld"><label>Lỗi thuộc nhà cung cấp</label><select name="supplierId"><option value="">Không / chưa rõ</option>@foreach ($suppliers as $s)<option value="{{ $s->supplierId }}" @selected((int) $c->supplierId === (int) $s->supplierId)>{{ $s->name }}</option>@endforeach</select></div>
                            <button class="ad-btn" type="submit">Lưu</button></form>
                        @if ($c->compensationStatus === 'pending')
                            <form method="POST" action="{{ route('admin.complaints.pay', $c->complaintId) }}" class="ad-form" style="margin-top:10px">@csrf
                                <div class="ad-fld"><label>Phương thức đã chi</label><select name="method"><option>Tiền mặt</option><option>Chuyển khoản</option><option>Ví điện tử</option></select></div>
                                <div class="ad-fld"><label>Mã giao dịch</label><input name="reference" maxlength="80"></div>
                                <button class="ad-btn ad-btn--primary" type="submit" data-confirm="Xác nhận đã chi trả {{ number_format($c->compensationAmount, 0, ',', '.') }} đ?">Đã chi trả</button></form>
                        @endif
                    @endif
                </div></section>
            @endif
        </div>
    </div>
@endsection
