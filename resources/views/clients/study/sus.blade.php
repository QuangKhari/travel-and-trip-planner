@include('clients.blocks.header')
@include('clients.support._style')
<main class="sp"><div class="sp-wrap" style="max-width:760px">
    <header class="sp-head"><h1>Phiếu đánh giá</h1>
        <p>Chọn mức đồng ý với từng câu: 1 = rất không đồng ý, 5 = rất đồng ý. Trả lời theo cảm nhận đầu tiên, không có đúng sai.</p></header>
    @if ($done !== null)
        <section class="sp-card"><div class="sp-card__b"><p style="margin:0">Đã ghi phiếu của bạn. Cảm ơn bạn đã tham gia buổi thử!</p></div></section>
    @else
    <form method="POST" action="{{ route('study.sus') }}" class="sp-card"><div class="sp-card__b">@csrf
        @if ($errors->any())<div class="sp-alert" role="alert">{{ $errors->first() }}</div>@endif
        <ol class="stsus">
            @foreach ($questions as $i => $q)
                <li><fieldset><legend>{{ $q }}</legend>
                    <div class="stsus__s">
                        @foreach (range(1, 5) as $v)
                            <label><input type="radio" name="q{{ $i + 1 }}" value="{{ $v }}" required @checked(old('q' . ($i + 1)) == $v)><span>{{ $v }}</span></label>
                        @endforeach
                    </div></fieldset></li>
            @endforeach
        </ol>
        <label for="stsusC" style="display:block;font-weight:600;margin:10px 0 4px">Điều bạn muốn Travela làm tốt hơn (không bắt buộc)</label>
        <textarea id="stsusC" name="comment" rows="3" maxlength="2000" style="width:100%">{{ old('comment') }}</textarea>
        <button class="theme-btn" style="margin-top:12px">Gửi phiếu</button>
    </div></form>
    @endif
</div></main>
<style>
.stsus { padding-left: 22px; margin: 0; }
.stsus li { margin-bottom: 14px; }
.stsus fieldset { border: 0; padding: 0; margin: 0; }
.stsus legend { font-size: .95rem; margin-bottom: 6px; }
.stsus__s { display: flex; gap: 8px; }
.stsus__s label { position: relative; margin: 0; }
.stsus__s input { position: absolute; opacity: 0; width: 100%; height: 100%; margin: 0; cursor: pointer; }
.stsus__s span { display: inline-flex; width: 44px; height: 40px; align-items: center; justify-content: center; border: 1px solid #b9c7cc; border-radius: 8px; background: #fff; font-weight: 600; }
.stsus__s input:checked + span { background: #135D65; border-color: #135D65; color: #fff; }
.stsus__s input:focus-visible + span { outline: 2px solid #135D65; outline-offset: 2px; }
</style>
@include('clients.blocks.footer')
