{{-- G6: khối "Hỏi nhanh" ở trang chi tiết điểm đến. Cần biến $destination (có slug, name). --}}
<section class="gx-box ask ask--quick" id="hoi-nhanh" data-ask data-scope="{{ $destination->slug }}" data-url="{{ route('ask.send') }}"
    data-csrf="{{ csrf_token() }}" data-login="{{ route('login') }}" data-logged="{{ session()->has('userId') ? 1 : 0 }}" aria-labelledby="askQuickTitle">
    <h2 id="askQuickTitle">Hỏi nhanh về {{ $destination->name }}</h2>
    <div class="ask__chips" data-ask-chips>
        @foreach (["Tháng 10 đi {$destination->name} có hợp không?", "{$destination->name} có món gì nên thử?", "Lễ hội nào ở {$destination->name}?"] as $c)
            <button type="button" class="ask__chip" data-ask-chip>{{ $c }}</button>
        @endforeach
    </div>
    <div class="ask__log" data-ask-log aria-live="polite" aria-relevant="additions"></div>
    @if (session()->has('userId'))
        <form class="ask__form" data-ask-form>
            <label class="sr-only" for="askQuickQ">Câu hỏi về {{ $destination->name }}</label>
            <input id="askQuickQ" name="question" type="text" minlength="3" maxlength="{{ (int) config('rag.limits.max_question_chars', 300) }}" required
                autocomplete="off" placeholder="Hỏi về {{ $destination->name }}...">
            <button type="submit" class="ask__send">Hỏi</button>
        </form>
        <p class="ask__quota" data-ask-quota></p>
    @else
        <p class="ask__login"><a href="{{ route('login') }}">Đăng nhập</a> để hỏi trợ lý về {{ $destination->name }}.</p>
    @endif
</section>
<link rel="stylesheet" href="{{ asset('clients/assets/css/ask.css') }}?v=1">
<script src="{{ asset('clients/assets/js/ask.js') }}?v=1" defer></script>
