@include('clients.blocks.header')
<main class="nl-library gx-page" id="main">
    <div class="gx-wrap">
        <nav class="gx-crumb" aria-label="Đường dẫn"><a href="{{ route('home') }}">Trang chủ</a><span aria-hidden="true">›</span><span>Danh sách đã lưu</span></nav>
        <header class="nl-library__head">
            <div><p class="gx-eyebrow">Bộ sưu tập cá nhân</p><h1>Những điều bạn muốn khám phá</h1>
                <p>Lưu điểm đến, món ăn và lễ hội để quay lại bất cứ lúc nào. Các mục trong khay lập kế hoạch được hiển thị riêng bên dưới.</p></div>
            <a class="nl-button" href="{{ route('destinations.list') }}"><i class="fas fa-compass" aria-hidden="true"></i> Khám phá điểm đến</a>
        </header>
        <nav class="nl-library__filters" aria-label="Lọc danh sách đã lưu">
            @foreach (['all'=>'Tất cả','destination'=>'Điểm đến','food'=>'Ẩm thực','event'=>'Lễ hội'] as $key=>$label)
                <a href="{{ route('saved.collection', $key==='all' ? [] : ['type'=>$key]) }}" class="{{ $filterType===$key ? 'is-current' : '' }}" @if($filterType===$key) aria-current="page" @endif>{{ $label }}</a>
            @endforeach
        </nav>
        @if ($saved->isEmpty())
            <div class="nl-library__empty"><i class="far fa-bookmark" aria-hidden="true"></i><h2>Chưa có mục nào trong danh sách này</h2><p>Trên trang chi tiết điểm đến, món ăn hoặc lễ hội, chọn “Lưu” để đưa nội dung vào đây.</p><a class="nl-button" href="{{ route('destinations.list') }}">Tìm điểm đến</a></div>
        @else
            <div class="nl-library__grid">
                @foreach($saved as $item)
                    <article class="nl-library__item" data-saved-item>
                        <span class="nl-library__tag">{{ ['destination'=>'Điểm đến','food'=>'Ẩm thực','event'=>'Lễ hội'][$item->type] }}</span>
                        <h2><a href="{{ $item->url }}">{{ $item->name }}</a></h2>
                        <p>Đã lưu {{ \Carbon\Carbon::parse($item->markedAt)->format('d/m/Y') }}</p>
                        <div class="nl-library__actions">
                            <a href="{{ $item->url }}">Xem chi tiết <i class="fas fa-arrow-right" aria-hidden="true"></i></a>
                            <button type="button" data-mark="saved" data-ref-type="{{ $item->type }}" data-slug="{{ $item->slug }}" data-label-on="Bỏ lưu" data-label-off="Lưu lại" aria-pressed="true" class="nl-remove is-on"><span data-label>Bỏ lưu</span></button>
                        </div>
                    </article>
                @endforeach
            </div>
        @endif
        <section class="nl-library__tour" aria-labelledby="nlFavToursTitle">
            <div><p class="gx-eyebrow">Tour yêu thích trên thiết bị</p><h2 id="nlFavToursTitle">Tour bạn đã đánh dấu</h2>
                <p>Tour yêu thích hiện được lưu riêng trong trình duyệt này, chưa đồng bộ với tài khoản. Để lưu các mục khám phá lên tài khoản, hãy dùng nút “Lưu” trong trang chi tiết.</p></div>
            <div id="nlFavTours" class="nl-library__grid" aria-live="polite"><p>Đang kiểm tra tour yêu thích trên thiết bị…</p></div>
            <a href="{{ route('tours') }}">Khám phá thêm tour <i class="fas fa-arrow-right" aria-hidden="true"></i></a>
        </section>
        <section class="nl-library__tray" aria-labelledby="nlTrayTitle">
            <div><p class="gx-eyebrow">Lên kế hoạch</p><h2 id="nlTrayTitle">Khay chuyến đi của bạn</h2><p>Khay khác với danh sách yêu thích: đây là những mục bạn chọn để tạo lịch trình.</p></div>
            @if(count($trayItems))
                <ul>@foreach($trayItems as $item)<li><span>{{ ['destination'=>'Điểm đến','food'=>'Ẩm thực','event'=>'Lễ hội','experience'=>'Trải nghiệm'][$item['type']] ?? 'Địa điểm' }}</span><a href="{{ $item['url'] }}">{{ $item['name'] }}</a></li>@endforeach</ul>
            @else
                <p>Chưa có địa điểm nào trong khay. Hãy chọn “Thêm vào chuyến đi” tại trang Khám phá.</p>
            @endif
            <button class="nl-button nl-button--secondary" type="button" data-tray-open>Quản lý khay chuyến đi</button>
        </section>
    </div>
</main>
@include('clients.blocks.footer')
