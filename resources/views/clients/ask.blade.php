@include('clients.blocks.header')
<link rel="stylesheet" href="{{ asset('clients/assets/css/ask.css') }}?v=1">

<main class="gx-page" id="main">
    <div class="gx-wrap">
        @include('clients.partials.gx.crumb', ['crumbs' => [['Trang chủ', route('home')], ['Hỏi đáp du lịch']]])

        <header class="gx-head">
            <p class="gx-eyebrow">Hỏi nhanh</p>
            <h1>Hỏi đáp du lịch</h1>
            <p class="gx-lead">Trả lời dựa trên cẩm nang, điểm đến, món ăn và lễ hội đã được duyệt, kèm nguồn và ngày cập nhật. Chưa có thông tin thì mình nói thẳng là chưa có, không đoán.</p>
        </header>

        <section class="ask" data-ask data-url="{{ route('ask.send') }}" data-csrf="{{ csrf_token() }}" data-login="{{ route('login') }}"
            data-logged="{{ $uid ? 1 : 0 }}" aria-label="Hỏi đáp">
            <div class="ask__log" data-ask-log aria-live="polite" aria-relevant="additions"></div>

            <div class="ask__chips" data-ask-chips>
                @foreach (['Thuê xe máy cần giấy tờ gì?', 'Dùng eSIM ở Việt Nam thế nào?', 'Tháng này đi đâu đẹp?', 'Lễ hội nào diễn ra tháng 9?'] as $c)
                    <button type="button" class="ask__chip" data-ask-chip>{{ $c }}</button>
                @endforeach
            </div>

            @if ($uid)
                <form class="ask__form" data-ask-form>
                    <label class="sr-only" for="askQ">Câu hỏi của bạn</label>
                    <input id="askQ" name="question" type="text" minlength="3" maxlength="{{ (int) config('rag.limits.max_question_chars', 300) }}" required
                        autocomplete="off" placeholder="Ví dụ: Tháng 10 đi Ninh Bình có hợp không?">
                    <button type="submit" class="ask__send">Hỏi</button>
                </form>
                <p class="ask__quota" data-ask-quota>Còn {{ $remaining }} lượt hỏi hôm nay.</p>
            @else
                <p class="ask__login">Bạn cần <a href="{{ route('login') }}">đăng nhập</a> để đặt câu hỏi (để giới hạn lượt dùng mỗi ngày).</p>
            @endif
        </section>
    </div>
</main>

<script src="{{ asset('clients/assets/js/ask.js') }}?v=1" defer></script>
@include('clients.blocks.footer')
