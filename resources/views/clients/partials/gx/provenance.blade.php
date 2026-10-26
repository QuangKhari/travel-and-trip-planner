{{-- Khối "Nguồn dữ liệu và cập nhật" dùng chung cho trang chi tiết (tuần 1-2, mục 16 của kế hoạch).
     Biến: $sources = list of [label, text, url|null]; $updated = 'Y-m-d' hoặc null. --}}
@if (!empty($sources) || !empty($updated))
    <section class="gx-box gx-prov" id="nguon" aria-label="Nguồn dữ liệu">
        <h2>Nguồn dữ liệu và cập nhật</h2>
        @if (!empty($updated))
            <p>Cập nhật lần cuối: <time datetime="{{ $updated }}">{{ date('d/m/Y', strtotime($updated)) }}</time></p>
        @endif
        @if (!empty($sources))
            <ul>
                @foreach ($sources as [$label, $text, $url])
                    <li><b>{{ $label }}:</b>
                        @if ($url)
                            <a href="{{ $url }}" rel="noopener nofollow" target="_blank">{{ $text }}</a>
                        @else
                            {{ $text }}
                        @endif
                    </li>
                @endforeach
            </ul>
        @endif
        <p class="gx-credit"><a href="{{ route('data.sources') }}">Xem cách Travela kiểm duyệt và ghi nguồn →</a></p>
    </section>
@endif
