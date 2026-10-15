{{-- CTA cuối trang kiểu "tranh màu nước" (signature Travela). Dùng ở /tours và /tour-detail.
     Tham số tuỳ chọn: $ctaTitle, $ctaText --}}
@php $wc = asset('clients/assets/images/watercolor') @endphp
<section class="wt-cta wt-paper" aria-labelledby="wtCtaTitle">
    <img class="wt-deco l wt-drift" src="{{ $wc }}/wc-leaf-a.svg" alt="" aria-hidden="true" loading="lazy">
    <img class="wt-deco r wt-drift wt-drift--slow" src="{{ $wc }}/wc-leaf-b.svg" alt=""
        aria-hidden="true" loading="lazy">
    <img class="wt-deco p" src="{{ $wc }}/wc-paper-plane.svg" alt="" aria-hidden="true" loading="lazy"
        data-fly>
    <div class="container">
        <div class="wt-cta__in wt-reveal">
            <h2 id="wtCtaTitle">{{ $ctaTitle ?? 'Sẵn sàng cho hành trình tiếp theo?' }}</h2>
            <p>{{ $ctaText ?? 'Chọn một chuyến đi hợp với bạn, hoặc nhắn cho chúng tôi để được tư vấn lịch trình phù hợp.' }}
            </p>
            <div class="wt-cta__btns">
                <a class="wt-btn" href="{{ route('tours') }}">Xem tất cả tour <i class="fas fa-arrow-right"></i></a>
                <a class="wt-btn wt-btn--ghost" href="{{ route('contact') }}">Nhận tư vấn</a>
            </div>
        </div>
    </div>
    <img class="wt-cta__scene" src="{{ $wc }}/wc-travel-scene.svg" alt="" aria-hidden="true"
        loading="lazy">
</section>
