@include('clients.blocks.header')
@include('clients.support._style')
<main class="sp"><div class="sp-wrap" style="max-width:720px">
    <header class="sp-head"><h1>Tham gia buổi thử Travela</h1>
        <p>Mã người thử: <b>{{ strtoupper($p->code) }}</b></p></header>
    <section class="sp-card"><div class="sp-card__b">
        <ul style="margin:0 0 14px;padding-left:20px;line-height:1.6">
            @foreach ($lines as $l)<li>{{ $l }}</li>@endforeach
        </ul>
        @if ($errors->any())<div class="sp-alert" role="alert">{{ $errors->first() }}</div>@endif
        <form method="POST" action="{{ route('study.consent') }}">@csrf
            <fieldset style="border:0;padding:0;margin:0 0 12px">
                <legend style="font-size:1rem;font-weight:600;margin-bottom:6px">Bạn đang dùng</legend>
                <label style="margin-right:16px"><input type="radio" name="device" value="desktop" required> Máy tính</label>
                <label><input type="radio" name="device" value="mobile"> Điện thoại</label>
            </fieldset>
            <label style="display:flex;gap:8px;align-items:flex-start;margin-bottom:14px"><input type="checkbox" name="agree" value="1" required style="margin-top:4px">
                <span>Tôi đã đọc và đồng ý tham gia.</span></label>
            <button class="theme-btn">Bắt đầu</button>
        </form>
        @if ($p->consentAt)<p style="margin:12px 0 0;color:#4b5563">Bạn đã đồng ý lúc {{ \Carbon\Carbon::parse($p->consentAt)->format('H:i d/m') }}. Thanh nhiệm vụ ở cuối màn hình.</p>@endif
    </div></section>
</div></main>
@include('clients.blocks.footer')
