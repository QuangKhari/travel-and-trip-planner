{{-- resources/views/clients/partials/wc-defs.blade.php
     Bộ lọc SVG dùng chung cho phong cách màu nước. Chỉ cần @include MỘT lần mỗi trang.
     Không hiển thị gì ra màn hình (width/height = 0). --}}
<svg width="0" height="0" style="position:absolute" aria-hidden="true" focusable="false">
    <defs>
        {{-- 1) "Vẽ lại" ảnh thật thành tranh màu nước: làm mềm chi tiết, loang mép, nhạt màu, thêm hạt giấy.
             Dùng cho ẢNH LỚN (hero, banner). KHÔNG dùng cho thẻ nhỏ vì tốn GPU. --}}
        <filter id="tvp-wc-paint" x="-2%" y="-2%" width="104%" height="104%" color-interpolation-filters="sRGB">
            <feGaussianBlur in="SourceGraphic" stdDeviation="0.8" result="soft" />
            <feTurbulence type="fractalNoise" baseFrequency="0.016 0.02" numOctaves="3" seed="7" result="warp" />
            <feDisplacementMap in="soft" in2="warp" scale="8" xChannelSelector="R" yChannelSelector="G"
                result="bleed" />
            <feColorMatrix in="bleed" type="saturate" values="0.86" result="sat" />
            <feComponentTransfer in="sat" result="wash">
                <feFuncR type="linear" slope="0.94" intercept="0.05" />
                <feFuncG type="linear" slope="0.94" intercept="0.05" />
                <feFuncB type="linear" slope="0.94" intercept="0.05" />
            </feComponentTransfer>
            <feTurbulence type="fractalNoise" baseFrequency="0.85" numOctaves="2" seed="3" result="grain" />
            <feColorMatrix in="grain" type="saturate" values="0" result="grainGray" />
            <feComponentTransfer in="grainGray" result="grainSoft">
                <feFuncR type="linear" slope="0.14" intercept="0.86" />
                <feFuncG type="linear" slope="0.14" intercept="0.86" />
                <feFuncB type="linear" slope="0.14" intercept="0.86" />
            </feComponentTransfer>
            <feBlend in="wash" in2="grainSoft" mode="multiply" result="paper" />
            <feComposite in="paper" in2="bleed" operator="in" />
        </filter>

        {{-- 2) Làm mép lem như giấy ướt: dùng cho đường sóng/viền nhỏ (rẻ, an toàn). --}}
        <filter id="tvp-wc-edge" x="-2%" y="-20%" width="104%" height="140%">
            <feTurbulence type="fractalNoise" baseFrequency="0.03 0.06" numOctaves="2" seed="11" result="n" />
            <feDisplacementMap in="SourceGraphic" in2="n" scale="7" xChannelSelector="R"
                yChannelSelector="G" />
        </filter>
    </defs>
</svg>
