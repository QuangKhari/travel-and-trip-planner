@include('admin.blocks.header')

<div class="container body">
    <div class="main_container">

        @include('admin.blocks.sidebar')

        <div class="right_col" role="main">

            <div class="page-title">
                <div class="title_left">
                    <h3>Điểm dừng <small>{{ $it->title }} · {{ $it->days }} ngày</small></h3>
                </div>
                <div class="title_right text-right">
                    <a class="btn btn-default" href="{{ route('admin.content.edit', ['itineraries', $it->itineraryId]) }}">« Về thông tin lịch trình</a>
                </div>
            </div>
            <div class="clearfix"></div>

            @if (session('success'))<div class="alert alert-success">{{ session('success') }}</div>@endif
            @if (session('error'))<div class="alert alert-danger">{{ session('error') }}</div>@endif
            @php $warn = session('warnings', $warnings ?? []); @endphp
            @if (!empty($warn))
                <div class="alert alert-warning"><b>Gợi ý chỉnh lịch trình</b> (vẫn lưu được):
                    <ul style="margin:6px 0 0 18px">@foreach ($warn as $w)<li>{{ $w }}</li>@endforeach</ul></div>
            @endif

            <div class="x_panel">
                <div class="x_content">
                    <p class="text-muted">
                        Mỗi dòng là một điểm dừng trong ngày. Chọn "Loại" rồi gõ tên để gắn với điểm đến / địa điểm / món / lễ hội có sẵn
                        (trang công khai sẽ có liên kết và bản đồ); để "Chữ tự do" nếu chỉ muốn ghi một dòng chữ; chọn "Di chuyển" cho đoạn đi lại
                        (chỉ ghi tên và ghi chú, không cần chọn mục). Chọn <b>khung giờ</b> (sáng, trưa, chiều, tối) và <b>số phút</b> để lịch hợp lý và không quá dày;
                        quỹ thời gian gợi ý mỗi ngày là {{ $budget }} phút. Thứ tự trong ngày là thứ tự các dòng từ trên xuống.
                    </p>

                    <form method="POST" action="{{ route('admin.itinerary.items.save', $it->itineraryId) }}" id="items-form">
                        @csrf
                        <input type="hidden" name="payload" id="payload">
                        <div id="days"></div>
                        <button class="btn btn-primary" type="submit">Lưu điểm dừng</button>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
(function () {
    var DAYS = {{ (int) $it->days }};
    var LOOKUP = @json(route('admin.content.lookup'));
    var items = @json($items);
    var SLOTS = @json($slots);
    var RANK = @json($slotRank);
    var BUDGET = {{ (int) $budget }};
    var TYPES = {'': 'Chữ tự do', destination: 'Điểm đến', place: 'Địa điểm', food: 'Món ăn', experience: 'Trải nghiệm', event: 'Lễ hội', transport: 'Di chuyển'};
    var root = document.getElementById('days');

    function el(tag, attrs, kids) {
        var e = document.createElement(tag);
        Object.keys(attrs || {}).forEach(function (k) { e[k] = attrs[k]; });
        (kids || []).forEach(function (c) { e.appendChild(typeof c === 'string' ? document.createTextNode(c) : c); });
        return e;
    }

    function row(day, it) {
        var tr = el('tr');
        tr.dataset.day = day;
        tr._ref = {refType: it.refType || '', refId: it.refId || null};

        var type = el('select', {className: 'form-control input-sm'});
        Object.keys(TYPES).forEach(function (k) { var o = el('option', {value: k, textContent: TYPES[k]}); if (k === (it.refType || '')) o.selected = true; type.appendChild(o); });

        var search = el('input', {className: 'form-control input-sm', placeholder: 'Gõ để tìm...', value: it.refName || ''});
        var list = el('div', {className: 'list-group', style: 'position:absolute;z-index:20;max-height:200px;overflow:auto;display:none'});
        var wrap = el('div', {style: 'position:relative'}, [search, list]);
        var label = el('input', {className: 'form-control input-sm', placeholder: 'Tên hiển thị', value: it.label || ''});
        var note = el('input', {className: 'form-control input-sm', placeholder: 'Ghi chú (tùy chọn)', value: it.note || ''});
        var slot = el('select', {className: 'form-control input-sm'});
        slot.appendChild(el('option', {value: '', textContent: 'Khung giờ'}));
        Object.keys(SLOTS).forEach(function (k) { var o = el('option', {value: k, textContent: SLOTS[k]}); if (k === (it.slot || '')) o.selected = true; slot.appendChild(o); });
        var dur = el('input', {className: 'form-control input-sm', type: 'number', min: 5, max: 1440, placeholder: 'Phút', value: it.durationMin || '', title: 'Thời lượng (phút)'});
        dur.addEventListener('input', totals);
        var up = el('button', {type: 'button', className: 'btn btn-xs btn-default', textContent: '↑'});
        var down = el('button', {type: 'button', className: 'btn btn-xs btn-default', textContent: '↓'});
        var del = el('button', {type: 'button', className: 'btn btn-xs btn-danger', textContent: '×'});

        function sync() {
            var free = !type.value || type.value === 'transport';
            search.disabled = free;
            if (free) { tr._ref = {refType: type.value, refId: null}; search.value = ''; }
        }
        type.addEventListener('change', function () { tr._ref = {refType: type.value, refId: null}; search.value = ''; sync(); });
        sync();

        var timer;
        search.addEventListener('input', function () {
            clearTimeout(timer);
            timer = setTimeout(function () {
                fetch(LOOKUP + '?type=' + encodeURIComponent(type.value) + '&q=' + encodeURIComponent(search.value), {headers: {'Accept': 'application/json'}})
                    .then(function (r) { return r.json(); })
                    .then(function (rows) {
                        list.innerHTML = '';
                        rows.forEach(function (r) {
                            var a = el('a', {href: '#', className: 'list-group-item', textContent: r.name});
                            a.addEventListener('click', function (e) {
                                e.preventDefault();
                                tr._ref = {refType: type.value, refId: r.id};
                                search.value = r.name;
                                if (!label.value.trim()) label.value = r.name;
                                list.style.display = 'none';
                            });
                            list.appendChild(a);
                        });
                        list.style.display = rows.length ? 'block' : 'none';
                    });
            }, 250);
        });
        document.addEventListener('click', function (e) { if (!wrap.contains(e.target)) list.style.display = 'none'; });

        up.addEventListener('click', function () { var p = tr.previousElementSibling; if (p) tr.parentNode.insertBefore(tr, p); });
        down.addEventListener('click', function () { var n = tr.nextElementSibling; if (n) tr.parentNode.insertBefore(n, tr); });
        del.addEventListener('click', function () { tr.remove(); totals(); });

        [el('td', {style: 'width:120px'}, [type]), el('td', {style: 'width:220px'}, [wrap]), el('td', {}, [label]), el('td', {style: 'width:110px'}, [slot]), el('td', {style: 'width:80px'}, [dur]), el('td', {}, [note]),
         el('td', {style: 'width:110px;white-space:nowrap'}, [up, ' ', down, ' ', del])].forEach(function (td) { tr.appendChild(td); });
        tr._get = function () {
            return {dayNo: day, refType: tr._ref.refType || null, refId: tr._ref.refId || null, label: label.value.trim(), note: note.value.trim(),
                slot: slot.value || null, durationMin: dur.value ? parseInt(dur.value, 10) : null};
        };
        return tr;
    }

    function totals() {
        root.querySelectorAll('tbody').forEach(function (tb) {
            var sum = 0, n = 0;
            tb.querySelectorAll('tr').forEach(function (tr) { var v = tr._get(); n++; if (v.durationMin) sum += v.durationMin; });
            var out = document.getElementById('sum-' + tb.dataset.day);
            if (out) {
                out.textContent = n + ' mục · ' + sum + ' phút đã ghi' + (sum > BUDGET ? ' (vượt quỹ ' + BUDGET + ' phút)' : '');
                out.className = sum > BUDGET ? 'text-danger' : 'text-muted';
            }
        });
    }

    function sortBySlot(body) {
        var rows = Array.prototype.slice.call(body.querySelectorAll('tr'));
        var keyed = rows.map(function (tr, i) { var s = tr._get().slot; return {tr: tr, i: i, r: s ? RANK[s] : 99}; });
        keyed.sort(function (a, b) { return a.r - b.r || a.i - b.i; });
        keyed.forEach(function (k) { body.appendChild(k.tr); });
    }

    function render() {
        for (var d = 1; d <= DAYS; d++) {
            var tbody = el('tbody');
            tbody.dataset.day = d;
            items.filter(function (i) { return i.dayNo === d; }).forEach(function (i) { tbody.appendChild(row(d, i)); });
            var add = el('button', {type: 'button', className: 'btn btn-sm btn-default', textContent: '+ Thêm điểm dừng'});
            var sort = el('button', {type: 'button', className: 'btn btn-sm btn-default', textContent: 'Sắp theo khung giờ'});
            (function (day, body) {
                add.addEventListener('click', function () { body.appendChild(row(day, {})); totals(); });
                sort.addEventListener('click', function () { sortBySlot(body); });
            })(d, tbody);
            root.appendChild(el('h4', {}, ['Ngày ' + d + ' ', el('small', {id: 'sum-' + d})]));
            root.appendChild(el('table', {className: 'table table-condensed'}, [tbody]));
            root.appendChild(add);
            root.appendChild(document.createTextNode(' '));
            root.appendChild(sort);
            root.appendChild(el('hr'));
        }
        var stray = items.filter(function (i) { return i.dayNo > DAYS; });
        if (stray.length) {
            root.insertBefore(el('div', {className: 'alert alert-warning', textContent: 'Có ' + stray.length + ' điểm dừng ở ngày vượt quá số ngày của lịch trình; chúng không hiện ở đây và sẽ bị xóa khi lưu. Tăng số ngày của lịch trình nếu muốn giữ.'}), root.firstChild);
        }
    }

    document.getElementById('items-form').addEventListener('submit', function (e) {
        var out = [];
        var bad = false;
        root.querySelectorAll('tbody').forEach(function (tb) {
            tb.querySelectorAll('tr').forEach(function (tr) {
                var v = tr._get();
                if (v.refType && v.refType !== 'transport' && !v.refId) { bad = true; }
                if (!v.label && !v.refId) { bad = true; }
                out.push(v);
            });
        });
        if (bad) { e.preventDefault(); alert('Có dòng chọn "Loại" nhưng chưa chọn mục trong danh sách gợi ý, hoặc chưa có tên hiển thị.'); return; }
        document.getElementById('payload').value = JSON.stringify(out);
    });

    render();
    totals();
})();
</script>
@include('admin.blocks.footer')
