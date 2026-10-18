{{-- CTA cuối trang: banner tranh màu nước vịnh Hạ Long (signature Travela). Dùng ở /tours và /blog-detail.
     Tham số tuỳ chọn: $ctaTitle, $ctaText, $ctaLabel, $ctaUrl.
     Ảnh nền không chứa chữ; chữ + nút là HTML thật. Chuyển động: ảnh "thở" chậm, nét cọ vàng quét ra (tự tắt khi bật "giảm chuyển động"). --}}
@php $ctaImg = asset('clients/assets/images/cta') @endphp
<section class="wt-cta wt-paper" aria-labelledby="wtCtaTitle">
    <div class="wt-cta__wrap">
        <figure class="wt-cta__banner">
            <picture>
                <source type="image/webp" srcset="{{ $ctaImg }}/cta-banner.webp">
                <img src="{{ $ctaImg }}/cta-banner.jpg" alt="" aria-hidden="true" width="1670"
                    height="557" loading="lazy" decoding="async">
            </picture>
            <div class="wt-cta__copy wt-reveal">
                <h2 id="wtCtaTitle">
                    {{ $ctaTitle ?? 'Cùng ' . config('site.name', 'Travel') . ' khám phá Việt Nam theo cách của bạn!' }}
                </h2>
                <p>{{ $ctaText ?? 'Hãy để chúng tôi đồng hành cùng bạn trên những hành trình đầy cảm hứng và trải nghiệm đáng nhớ.' }}
                </p>
                <a class="wt-cta__btn"
                    href="{{ $ctaUrl ?? route('tours') }}">{{ $ctaLabel ?? 'Xem các tour nổi bật' }}<span
                        class="wt-cta__arr" aria-hidden="true"></span></a>
            </div>
        </figure>
    </div>
</section>
