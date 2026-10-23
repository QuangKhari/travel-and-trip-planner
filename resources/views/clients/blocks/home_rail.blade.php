{{--
  Một dải ngang dùng chung cho trang chủ (D2).
  Tham số: $id, $eyebrow, $title, $lead (tuỳ chọn), $items (mảng thẻ cho gx.card),
           $moreUrl, $moreLabel (tuỳ chọn), $cta = ['url'=>, 'label'=>] (tuỳ chọn, nút cạnh tiêu đề),
           $tail (tuỳ chọn, true thì chèn thẻ tối "Lên kế hoạch với AI" cuối dải; cần $plannerHref).
  Rỗng thì không vẽ gì (không để tiêu đề cụt).
--}}
@if (count($items))
    <section class="hs" id="{{ $id }}" aria-labelledby="{{ $id }}-h">
        <div class="container">
            <header class="hs-head">
                <div>
                    <p class="hs-eyebrow">{{ $eyebrow }}</p>
                    <h2 id="{{ $id }}-h">{{ $title }}</h2>
                    @isset($lead)
                        <p class="hs-lead">{{ $lead }}</p>
                    @endisset
                </div>
                <div class="hs-tools">
                    @isset($cta)
                        <a class="hs-cta" href="{{ $cta['url'] }}">{{ $cta['label'] }}</a>
                    @endisset
                    <span class="hs-arrows" aria-hidden="true">
                        <button type="button" class="hs-arrow" data-dir="-1" tabindex="-1">‹</button>
                        <button type="button" class="hs-arrow" data-dir="1" tabindex="-1">›</button>
                    </span>
                </div>
            </header>
            <div class="gx-rail hs-rail" data-rail>
                @foreach ($items as $c)
                    @include('clients.partials.gx.card', ['c' => $c, 'loading' => 'lazy'])
                @endforeach
                @if (!empty($tail))
                    <a class="hs-ai" href="{{ $plannerHref }}">
                        <span class="hs-ai__k">Lên kế hoạch</span>
                        <b>Chưa biết bắt đầu từ đâu?</b>
                        <span>Chọn điểm đến, số ngày và tháng đi. Travela gợi ý lịch trình và tour hợp lịch.</span>
                        <i>Bắt đầu →</i>
                    </a>
                @endif
            </div>
            @isset($moreUrl)
                <p class="hs-more"><a href="{{ $moreUrl }}">{{ $moreLabel ?? 'Xem tất cả' }} →</a></p>
            @endisset
        </div>
    </section>
@endif
