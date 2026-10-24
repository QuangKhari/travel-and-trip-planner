{{--
  Hero "danh sách 3 mục + ảnh ghép ô đổi theo mục đang rê chuột" (học từ GoVietnam /foods, /experience).
  Tham số: $items = mảng object có ->title, ->url, ->image (bắt buộc), ->meta (tuỳ chọn); $cols, $rows (mặc định 3x3).
  Cần: gx-discover.css + gx-discover.js. Không đủ 2 mục có ảnh thì không vẽ gì. Không có JS: hiện ảnh đầu tiên.
--}}
@php
    $heroItems = collect($items ?? [])->filter(fn($c) => !empty($c->image))->take(3)->values();
@endphp
@if ($heroItems->count() >= 2)
    <section class="gh" data-gh data-cols="{{ $cols ?? 3 }}" data-rows="{{ $rows ?? 3 }}" aria-label="{{ $label ?? 'Gợi ý nổi bật' }}">
        <ol class="gh-list">
            @foreach ($heroItems as $i => $c)
                <li>
                    <a class="gh-item {{ $i === 0 ? 'is-on' : '' }}" href="{{ $c->url }}" data-img="{{ $c->image }}">
                        <span class="gh-n">{{ sprintf('%02d', $i + 1) }}</span>
                        <span class="gh-t">{{ $c->title }}@if (!empty($c->meta))<small>{{ $c->meta }}</small>@endif</span>
                    </a>
                </li>
            @endforeach
        </ol>
        <div class="gh-stage" aria-hidden="true">
            <img class="gh-fb" src="{{ $heroItems[0]->image }}" alt="" width="640" height="480" decoding="async">
            <div class="gh-tiles"></div>
        </div>
    </section>
@endif
