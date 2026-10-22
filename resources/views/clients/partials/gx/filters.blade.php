{{-- Bộ lọc phía server (GET, giữ trên URL). $action: URL; $fields: [[name, nhãn, [giá trị => nhãn]]]; $filters: giá trị hiện tại; $search: nhãn ô tìm (hoặc null). --}}
<form class="gx-filters" method="get" action="{{ $action }}" role="search">
    @if (!empty($search))
        <label>{{ $search }}
            <input type="search" name="q" value="{{ $filters['q'] ?? '' }}" maxlength="60" autocomplete="off">
        </label>
    @endif
    @foreach ($fields as [$name, $label, $options])
        <label>{{ $label }}
            <select name="{{ $name }}" onchange="this.form.submit()">
                <option value="">Tất cả</option>
                @foreach ($options as $value => $text)
                    <option value="{{ $value }}" @selected((string) ($filters[$name] ?? '') === (string) $value)>{{ $text }}</option>
                @endforeach
            </select>
        </label>
    @endforeach
    <button type="submit" class="gx-btn">Lọc</button>
    @if (collect($filters)->filter(fn($v) => $v !== null && $v !== '')->isNotEmpty())
        <a class="gx-btn2" href="{{ $action }}">Xóa bộ lọc</a>
    @endif
</form>
