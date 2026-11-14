@extends('admin.layout')
@section('content')
    @php $hm = fn($m) => $m === null ? '' : sprintf('%02d:%02d', intdiv($m, 60), $m % 60); $rows = collect($stops)->flatten(1); @endphp
    <div class="ad-head"><div><h1>Lịch trình theo giờ · {{ $tour->title }}</h1>
        <p>Mỗi dòng là một điểm dừng: ngày, giờ, loại (tập trung, tham quan, ăn, di chuyển, nghỉ, trả khách). Khách thấy ở trang tour; điểm tập trung và giờ trả khách giúp khách đặt vé đi / về đúng giờ. Tour {{ $days }} ngày.</p></div>
        <a class="ad-btn ad-btn--ghost" href="{{ route('admin.tours') }}">← Danh sách tour</a></div>
    @if (!$ready)
        <div class="ad-card"><div class="ad-empty"><i class="fa fa-database"></i><b>Chưa chạy migration</b></div></div>
    @else
        @if ($errors->any())<div class="ad-alert ad-alert--bad">{{ $errors->first() }}</div>@endif
        @if ($issues)
            <div class="ad-alert" style="background:#fff7e6;border:1px solid #f3d19e;padding:10px 14px;border-radius:10px;margin-bottom:12px"><b>Nên kiểm tra:</b><ul style="margin:4px 0 0">@foreach ($issues as $i)<li>{{ $i }}</li>@endforeach</ul></div>
        @endif
        <form method="POST" action="{{ route('admin.tour-stops.save', $tour->tourId) }}" class="ad-card" style="padding:12px 16px">@csrf
            <table class="ad-table" id="tsTable"><thead><tr><th style="width:70px">Ngày</th><th style="width:100px">Từ</th><th style="width:100px">Đến</th><th style="width:150px">Loại</th><th style="min-width:240px">Tên điểm dừng</th><th style="min-width:180px">Ghi chú</th><th style="width:110px">Mã địa điểm</th><th></th></tr></thead>
                <tbody>
                @php $old = old('stops', $rows->map(fn($s) => ['dayNo' => $s->dayNo, 'start' => $hm($s->startMin), 'end' => $hm($s->endMin), 'kind' => $s->kind, 'title' => $s->title, 'note' => $s->note, 'placeId' => $s->refType === 'place' ? $s->refId : null])->all()); @endphp
                @foreach (array_values($old) as $i => $s)
                    <tr><td><input type="number" name="stops[{{ $i }}][dayNo]" min="1" max="{{ $days }}" value="{{ $s['dayNo'] }}" required style="width:60px"></td>
                        <td><input type="time" name="stops[{{ $i }}][start]" value="{{ $s['start'] }}"></td><td><input type="time" name="stops[{{ $i }}][end]" value="{{ $s['end'] }}"></td>
                        <td><select name="stops[{{ $i }}][kind]">@foreach ($kinds as $k => $l)<option value="{{ $k }}" @selected($s['kind'] === $k)>{{ $l }}</option>@endforeach</select></td>
                        <td><input name="stops[{{ $i }}][title]" value="{{ $s['title'] }}" maxlength="160" required style="width:100%"></td>
                        <td><input name="stops[{{ $i }}][note]" value="{{ $s['note'] }}" maxlength="400" style="width:100%"></td>
                        <td><input type="number" name="stops[{{ $i }}][placeId]" value="{{ $s['placeId'] }}" min="1" style="width:100px"></td>
                        <td><button type="button" class="ad-btn ad-btn--sm ad-btn--ghost" onclick="this.closest('tr').remove()">Xóa</button></td></tr>
                @endforeach
                </tbody></table>
            <div style="display:flex;gap:8px;margin-top:10px">
                <button type="button" class="ad-btn ad-btn--ghost" id="tsAdd">+ Thêm điểm dừng</button>
                <button class="ad-btn ad-btn--primary" style="width:auto">Lưu lịch trình</button></div>
        </form>
        <template id="tsRow"><tr><td><input type="number" min="1" max="{{ $days }}" value="1" required style="width:60px" data-n="dayNo"></td><td><input type="time" data-n="start"></td><td><input type="time" data-n="end"></td>
            <td><select data-n="kind">@foreach ($kinds as $k => $l)<option value="{{ $k }}">{{ $l }}</option>@endforeach</select></td><td><input maxlength="160" required style="width:100%" data-n="title"></td>
            <td><input maxlength="400" style="width:100%" data-n="note"></td><td><input type="number" min="1" style="width:100px" data-n="placeId"></td>
            <td><button type="button" class="ad-btn ad-btn--sm ad-btn--ghost" onclick="this.closest('tr').remove()">Xóa</button></td></tr></template>
        <script>
            document.getElementById('tsAdd').addEventListener('click', function () {
                var tb = document.querySelector('#tsTable tbody'), i = Date.now() % 100000, row = document.getElementById('tsRow').content.firstElementChild.cloneNode(true);
                row.querySelectorAll('[data-n]').forEach(function (x) { x.name = 'stops[' + i + '][' + x.dataset.n + ']'; });
                var last = tb.lastElementChild; if (last) { var d = last.querySelector('input[name$="[dayNo]"]'); if (d) row.querySelector('[data-n="dayNo"]').value = d.value; }
                tb.appendChild(row); row.querySelector('[data-n="title"]').focus();
            });
        </script>
        @include('admin.tour-addons-card')
    @endif
@endsection
