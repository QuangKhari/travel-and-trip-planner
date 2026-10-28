{{-- Một ô của form soạn nội dung. Biến: $name, $f (cấu hình [nhãn, kiểu, luật, enum?]), $row, $kind, $destinations, $categories, $categoryHints, $isNew --}}
@php
    $type = $f[1];
    $val = old($name, $row->$name ?? null);
    $label = $f[0];
    // Nhãn và gợi ý ngắn gọn, dễ hiểu hơn nhãn kỹ thuật trong cấu hình
    $label = match (true) {
        $name === 'category' && $kind === 'foods' => 'Nhóm món',
        $name === 'category' && $kind === 'experiences' => 'Loại trải nghiệm',
        $name === 'destinationId' => 'Điểm đến',
        $name === 'duration' => 'Thời lượng (chữ, ví dụ Nửa ngày)',
        $name === 'placeId' => 'Mã địa điểm ăn uống (placeId)',
        $name === 'whereToTry' => 'Nơi nên thử',
        $name === 'monthFrom' => ($kind === 'foods' ? 'Mùa ngon: từ tháng' : 'Mùa phù hợp: từ tháng'),
        $name === 'monthTo' => 'Đến tháng',
        $name === 'lunarMonth' => 'Tháng âm lịch',
        $name === 'lunarDay' => 'Ngày âm lịch',
        $name === 'solarMonth' => 'Tháng dương lịch',
        $name === 'solarDay' => 'Ngày dương lịch',
        $name === 'durationDays' => 'Diễn ra mấy ngày',
        $name === 'dateNote' => 'Ghi chú về ngày (không bắt buộc)',
        $name === 'priceLevel' => 'Mức giá',
        $name === 'latitude' => 'Vĩ độ',
        $name === 'longitude' => 'Kinh độ',
        $name === 'durationMinutes' => 'Thời lượng (phút)',
        $name === 'difficulty' => 'Độ khó',
        $name === 'indoor' => 'Trong nhà hay ngoài trời',
        $name === 'body' => 'Nội dung',
        default => $label,
    };
    $hints = [
        'destinationId' => 'Bắt buộc để xuất bản món ăn, trải nghiệm, lễ hội.',
        'category' => $kind === 'foods' ? 'Chọn nhóm đã có trong gợi ý để bộ lọc không bị tách đôi; gõ nhóm mới nếu chưa có.' : 'Ví dụ: Thiên nhiên, Văn hóa, Ẩm thực.',
        'description' => 'Viết 2–4 câu, tối thiểu 30 ký tự thì mới xuất bản được.',
        'summary' => 'Viết 2–4 câu, tối thiểu 30 ký tự thì mới xuất bản được.',
        'body' => 'Dùng Markdown: "## " là tiêu đề mục, "> " là hộp lưu ý, bảng Markdown cho "Tóm tắt nhanh". Tối thiểu 30 ký tự.',
        'whereToTry' => 'Chỉ ghi khi có nguồn.',
        'placeId' => 'Số placeId trong trang Địa điểm (OSM). Để trống nếu chưa xác minh.',
        'monthTo' => 'Chọn tháng nhỏ hơn tháng bắt đầu nghĩa là mùa kéo qua năm sau.',
        'durationMinutes' => 'Bắt buộc để trải nghiệm vào được Planner.',
        'difficulty' => 'Bắt buộc để trải nghiệm vào được Planner.',
        'priceNote' => 'Chỉ khi có nguồn, ghi rõ "tham khảo".',
        'priceMin' => 'VND, chỉ khi có nguồn.',
        'priceMax' => 'VND, chỉ khi có nguồn.',
        'latitude' => 'Chỉ điền khi xác minh được vị trí riêng của trải nghiệm.',
        'dateNote' => 'Ví dụ: "Chính hội 10/3 âm lịch".',
        'isMustTry' => 'Hiện nhãn vàng "Phải thử" trên thẻ.',
        'isFeatured' => 'Ưu tiên hiển thị ở trang chủ và hero.',
    ];
    $hint = $hints[$name] ?? null;
    $months = range(1, 12);
    $isMonth = in_array($name, ['monthFrom', 'monthTo', 'lunarMonth', 'solarMonth'], true);
    $cls = in_array($name, ['lunarMonth', 'lunarDay'], true) ? 'js-lunar' : (in_array($name, ['solarMonth', 'solarDay'], true) ? 'js-solar' : '');
@endphp
@if ($type === 'bool')
    <div class="form-group {{ $cls }}">
        <div class="checkbox">
            <label>
                <input type="hidden" name="{{ $name }}" value="0">
                <input type="checkbox" name="{{ $name }}" value="1" @checked((int) $val === 1)> <b>{{ $name === 'isMustTry' ? 'Phải thử' : 'Nổi bật' }}</b>
            </label>
        </div>
        @if ($hint)<small class="text-muted">{{ $hint }}</small>@endif
    </div>
@elseif ($name === 'isLunar')
    @php $lunar = (string) ($val ?? ($isNew ? '1' : '1')); @endphp
    <div class="form-group @error($name) has-error @enderror">
        <label>Lễ hội tính theo lịch nào?</label>
        <div>
            <label class="radio-inline"><input type="radio" name="isLunar" value="1" @checked($lunar === '1')> Âm lịch (ví dụ Giỗ Tổ, Tết)</label>
            <label class="radio-inline"><input type="radio" name="isLunar" value="0" @checked($lunar === '0')> Dương lịch (ví dụ 30/4)</label>
        </div>
        @error($name)<span class="help-block">{{ $message }}</span>@enderror
    </div>
@else
    <div class="form-group {{ $cls }} @error($name) has-error @enderror">
        <label for="f_{{ $name }}">{{ $label }}</label>
        @if ($type === 'textarea')
            <textarea id="f_{{ $name }}" name="{{ $name }}" rows="{{ $name === 'body' ? 16 : 5 }}" class="form-control">{{ $val }}</textarea>
        @elseif ($type === 'dest')
            <select id="f_{{ $name }}" name="{{ $name }}" class="form-control">
                <option value="">(chưa gắn điểm đến)</option>
                @foreach ($destinations as $d)
                    <option value="{{ $d->destinationId }}" @selected((string) $val === (string) $d->destinationId)>{{ $d->name }}{{ $d->isActive ? '' : ' (đã tắt)' }}</option>
                @endforeach
            </select>
        @elseif ($type === 'category')
            <select id="f_{{ $name }}" name="{{ $name }}" class="form-control">
                <option value="">(chưa có danh mục, hiện ở mục "Khác")</option>
                @foreach ($categories as $c)
                    <option value="{{ $c->categoryId }}" @selected((string) $val === (string) $c->categoryId)>{{ $c->name }}</option>
                @endforeach
            </select>
        @elseif ($type === 'enum')
            <select id="f_{{ $name }}" name="{{ $name }}" class="form-control">
                <option value="">(không chọn)</option>
                @foreach ($f[3] as $code => $text)
                    <option value="{{ $code }}" @selected((string) $val === (string) $code)>{{ $text }}</option>
                @endforeach
            </select>
        @elseif ($isMonth)
            <select id="f_{{ $name }}" name="{{ $name }}" class="form-control">
                <option value="">(không chọn)</option>
                @foreach ($months as $m)<option value="{{ $m }}" @selected((string) $val === (string) $m)>Tháng {{ $m }}</option>@endforeach
            </select>
        @elseif ($name === 'priceLevel')
            <select id="f_{{ $name }}" name="{{ $name }}" class="form-control">
                <option value="">(không chọn)</option>
                @foreach ([1 => '1 · Rẻ', 2 => '2 · Vừa phải', 3 => '3 · Khá đắt', 4 => '4 · Đắt'] as $code => $text)
                    <option value="{{ $code }}" @selected((string) $val === (string) $code)>{{ $text }}</option>
                @endforeach
            </select>
        @elseif ($type === 'number')
            <input id="f_{{ $name }}" type="number" name="{{ $name }}" value="{{ $val }}" class="form-control" @if (in_array($name, ['latitude', 'longitude'], true)) step="any" @endif>
        @else
            <input id="f_{{ $name }}" type="text" name="{{ $name }}" value="{{ $val }}" class="form-control"
                @if ($name === 'category') list="categoryHints" @endif>
            @if ($name === 'category' && !empty($categoryHints))
                <datalist id="categoryHints">@foreach ($categoryHints as $h)<option value="{{ $h }}">@endforeach</datalist>
            @endif
        @endif
        @if ($hint)<small class="text-muted">{{ $hint }}</small>@endif
        @error($name)<span class="help-block">{{ $message }}</span>@enderror
    </div>
@endif
