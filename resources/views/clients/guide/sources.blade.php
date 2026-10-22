@php
    $metaDescription = 'Travela lấy dữ liệu từ đâu: nguồn, giấy phép và cách ghi công cho điểm đến, ẩm thực, lễ hội, lịch trình, cẩm nang, ảnh và bản đồ.';
    $sources = [
        ['Wikidata', 'Tên, tọa độ, ngày lễ của món ăn và lễ hội', 'CC0 (phạm vi công cộng)', 'https://www.wikidata.org/wiki/Wikidata:Licensing'],
        ['Wikipedia tiếng Việt', 'Đoạn giới thiệu điểm đến, món ăn, lễ hội', 'CC BY-SA 4.0: ghi nguồn và chia sẻ giống nhau', 'https://creativecommons.org/licenses/by-sa/4.0/deed.vi'],
        ['Wikimedia Commons', 'Một phần ảnh bìa', 'Tùy từng ảnh; tác giả và giấy phép ghi ngay dưới ảnh', 'https://commons.wikimedia.org/'],
        ['OpenStreetMap', 'Vị trí địa điểm và nền bản đồ', 'ODbL 1.0: ghi "© OpenStreetMap contributors"', 'https://www.openstreetmap.org/copyright'],
        ['Open-Meteo', 'Khí hậu nhiều năm và thời tiết hiện tại', 'CC BY 4.0', 'https://open-meteo.com/en/license'],
        ['Leaflet', 'Thư viện hiển thị bản đồ', 'BSD 2-Clause', 'https://leafletjs.com/'],
        ['Travela biên tập', 'Mô tả tự viết, lịch trình, cẩm nang, đánh giá mùa đẹp', 'Tự viết, đã qua bước duyệt trước khi hiển thị', null],
    ];
@endphp
@include('clients.blocks.header')
{{-- /nguon-du-lieu — biến: $counts --}}
<main class="gx-page" id="main">
    <div class="gx-wrap">
        @include('clients.partials.gx.crumb', ['crumbs' => [['Trang chủ', route('home')], ['Nguồn dữ liệu', null]]])
        <header class="gx-head">
            <p class="gx-eyebrow">Minh bạch</p>
            <h1>Nguồn dữ liệu</h1>
            <p class="gx-lead">Mọi nội dung hiển thị trên Travela đều qua bước duyệt. Nội dung lấy từ nguồn mở được ghi công ngay tại nơi hiển thị.</p>
        </header>

        <section class="gx-box" aria-labelledby="h-nguon">
            <h2 id="h-nguon">Nguồn và giấy phép</h2>
            <div class="gx-matrix-wrap" tabindex="0" role="region" aria-label="Bảng nguồn dữ liệu">
                <table class="gx-table">
                    <thead><tr><th scope="col">Nguồn</th><th scope="col">Dùng cho</th><th scope="col">Giấy phép</th></tr></thead>
                    <tbody>
                        @foreach ($sources as [$name, $use, $license, $url])
                            <tr>
                                <th scope="row">@if ($url)<a href="{{ $url }}" target="_blank" rel="noopener noreferrer">{{ $name }}</a>@else{{ $name }}@endif</th>
                                <td>{{ $use }}</td>
                                <td>{{ $license }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </section>

        <section class="gx-box" aria-labelledby="h-duyet">
            <h2 id="h-duyet">Cách chúng tôi duyệt nội dung</h2>
            <p>Nội dung nhập tự động luôn ở trạng thái nháp. Chỉ khi có mô tả đủ dài, giấy phép rõ ràng và ghi công đầy đủ, người biên tập mới duyệt để hiển thị. Thông tin dễ thay đổi (visa, giấy tờ, quy định) luôn ghi ngày cập nhật lần cuối và liên kết tới nguồn chính thức.</p>
            <p>Mức "đẹp nhất", "đi được", "nên tránh" của từng tháng được tính từ dữ liệu khí hậu nhiều năm rồi người biên tập chỉnh lại. Đây là gợi ý để chọn tháng đi, không phải dự báo thời tiết.</p>
        </section>

        <section class="gx-box" aria-labelledby="h-sl">
            <h2 id="h-sl">Hiện có</h2>
            <ul class="gx-links">
                @foreach ($counts as $label => $n)
                    <li><strong>{{ number_format($n) }}</strong> {{ mb_strtolower($label) }}</li>
                @endforeach
            </ul>
            <p>Phát hiện sai sót? <a href="{{ route('contact') }}">Báo cho chúng tôi</a>.</p>
        </section>
    </div>
</main>
@include('clients.blocks.footer')
