<!DOCTYPE html>
<html lang="vi">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex">
<title>Góp câu thử trợ lý lên lịch · Travela</title>
<style>
    :root { --brand: #0f766e; --line: #e5e7eb; --mute: #64748b; }
    * { box-sizing: border-box; }
    body { margin: 0; font: 15px/1.5 system-ui, -apple-system, "Segoe UI", Roboto, sans-serif; color: #0f172a; background: #f8fafc; }
    .wrap { max-width: 880px; margin: 0 auto; padding: 24px 16px 48px; }
    h1 { font-size: 1.4rem; margin: 0 0 4px; } h2 { font-size: 1.05rem; margin: 0 0 10px; }
    .card { background: #fff; border: 1px solid var(--line); border-radius: 12px; padding: 16px; margin-top: 16px; }
    .mute { color: var(--mute); } .small { font-size: .85rem; }
    .grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(150px, 1fr)); gap: 10px; }
    label { display: block; font-weight: 600; font-size: .85rem; margin-bottom: 4px; }
    input[type=text], input[type=number], select, textarea { width: 100%; padding: 8px 10px; border: 1px solid #cbd5e1; border-radius: 8px; font: inherit; }
    textarea { min-height: 80px; }
    .chips { display: flex; flex-wrap: wrap; gap: 6px 12px; } .chips label { font-weight: 400; display: inline-flex; gap: 4px; align-items: center; margin: 0; }
    .grp { display: grid; grid-template-columns: repeat(auto-fill, minmax(250px, 1fr)); gap: 8px; }
    .grp label { font-weight: 400; border: 1px solid var(--line); border-radius: 10px; padding: 8px 10px; cursor: pointer; display: block; }
    .grp input { margin-right: 6px; } .grp b { display: block; } .grp small { color: var(--mute); }
    .count { float: right; font-size: .75rem; color: var(--brand); }
    button { background: var(--brand); color: #fff; border: 0; border-radius: 8px; padding: 10px 18px; font: inherit; font-weight: 600; cursor: pointer; }
    .msg { background: #ecfdf5; border: 1px solid #a7f3d0; padding: 10px 12px; border-radius: 8px; margin-top: 12px; }
    .err { background: #fef2f2; border-color: #fecaca; }
    ul.mine { list-style: none; padding: 0; margin: 0; } ul.mine li { padding: 8px 0; border-top: 1px solid var(--line); display: flex; gap: 10px; align-items: start; }
    ul.mine li span { flex: 1; } .linkbtn { background: none; color: #b91c1c; padding: 0; font-weight: 400; }
</style>
</head>
<body><div class="wrap">
    <h1>Góp câu thử trợ lý lên lịch của Travela</h1>
    <p class="mute">Cảm ơn bạn đã giúp! Hãy viết các câu như khi bạn nhắn cho một người bạn để nhờ lên kế hoạch đi chơi trong nước.
        Sau mỗi câu, đánh dấu <b>ý bạn muốn nói</b> (không phải điều bạn đoán hệ thống sẽ hiểu). Mục tiêu khoảng {{ $target }} câu mỗi nhóm; viết bao nhiêu cũng được.</p>
    <p class="small mute">Không cần tài khoản. Chỉ lưu câu bạn viết và mã người viết <b>{{ $writer }}</b> (không lưu email, tên thật). Câu sẽ được dùng ẩn danh trong luận văn.</p>

    @if (session('peer_msg'))<div class="msg">{{ session('peer_msg') }}</div>@endif
    @if ($errors->any())<div class="msg err">{{ $errors->first() }}</div>@endif

    <form class="card" method="post" action="{{ $postUrl }}">
        @csrf
        <h2>1. Chọn nhóm câu</h2>
        <div class="grp">
            @foreach ($groups as $g => $label)
                <label><input type="radio" name="grp" value="{{ $g }}" @checked(old('grp') === $g) required>
                    <b>{{ ucfirst($label) }} <span class="count">{{ (int) ($count[$g] ?? 0) }}/{{ $target }}</span></b><small>{{ $hints[$g] ?? '' }}</small></label>
            @endforeach
        </div>

        <h2 style="margin-top:16px">2. Viết câu</h2>
        <textarea name="q" maxlength="500" required placeholder="Ví dụ: cuối tháng 12 nhà mình 4 người muốn đi đâu đó có biển, tầm 3 ngày, đừng đi xa quá">{{ old('q') }}</textarea>

        <h2 style="margin-top:16px">3. Ý bạn muốn nói <span class="small mute">(để trống những gì câu không nói tới)</span></h2>
        <div class="grid">
            <div><label>Đi đâu</label><select name="destination"><option value="">— không nói / không có —</option>
                @foreach ($dests as $d)<option value="{{ $d->slug }}" @selected(old('destination') === $d->slug)>{{ $d->name }}</option>@endforeach</select></div>
            <div><label>Tháng</label><input type="number" name="month" min="1" max="12" value="{{ old('month') }}"></div>
            <div><label>Số ngày</label><input type="number" name="days" min="1" max="30" value="{{ old('days') }}"></div>
            <div><label>Người lớn</label><input type="number" name="adults" min="1" max="50" value="{{ old('adults') }}"></div>
            <div><label>Trẻ em</label><input type="number" name="children" min="0" max="20" value="{{ old('children') }}"></div>
            <div><label>Ngân sách cả chuyến (đ)</label><input type="number" name="budget" min="0" step="100000" value="{{ old('budget') }}"></div>
            <div><label>Nhịp đi</label><select name="pace"><option value="">—</option>@foreach ($paces as $k => $v)<option value="{{ $k }}" @selected(old('pace') === $k)>{{ $v }}</option>@endforeach</select></div>
        </div>
        <div style="margin-top:12px"><label>Thích</label><div class="chips">
            @foreach ($interests as $k => $v)<label><input type="checkbox" name="interests[]" value="{{ $k }}" @checked(in_array($k, (array) old('interests', []), true))> {{ $v }}</label>@endforeach</div></div>
        <div style="margin-top:12px"><label>Không thích</label><div class="chips">
            @foreach ($dislikes as $k => $v)<label><input type="checkbox" name="dislikes[]" value="{{ $k }}" @checked(in_array($k, (array) old('dislikes', []), true))> {{ $v }}</label>@endforeach</div></div>
        <div style="margin-top:12px"><label>Điều bạn chỉ NGỤ Ý, không nói thẳng <span class="mute small">(vd "đi với bố mẹ" = 3 người lớn)</span></label><div class="chips">
            @foreach (['adults' => 'Số người lớn', 'children' => 'Trẻ em', 'pace' => 'Nhịp đi', 'month' => 'Tháng', 'days' => 'Số ngày', 'budget' => 'Ngân sách'] as $k => $v)
                <label><input type="checkbox" name="inferred[]" value="{{ $k }}" @checked(in_array($k, (array) old('inferred', []), true))> {{ $v }}</label>@endforeach</div></div>
        <div style="margin-top:12px"><label>Trợ lý NÊN HỎI LẠI vì câu không nói</label><div class="chips">
            @foreach (['destination' => 'Đi đâu', 'days' => 'Mấy ngày', 'month' => 'Khi nào', 'people' => 'Mấy người'] as $k => $v)
                <label><input type="checkbox" name="needs[]" value="{{ $k }}" @checked(in_array($k, (array) old('needs', []), true))> {{ $v }}</label>@endforeach</div></div>
        <div style="margin-top:12px"><label>Chỉ cho nhóm "chèn câu lệnh lạ": chuỗi trợ lý KHÔNG được nói ra hay điền vào <span class="mute small">(cách nhau bằng dấu phẩy)</span></label>
            <input type="text" name="mustNotContain" maxlength="200" value="{{ old('mustNotContain') }}" placeholder="vd: paris, mật khẩu"></div>
        <p style="margin-top:16px"><button type="submit">Lưu câu này</button></p>
    </form>

    <div class="card">
        <h2>Câu bạn đã gửi ({{ $mine->count() }})</h2>
        @if ($mine->isEmpty())<p class="mute">Chưa có câu nào.</p>@else
        <ul class="mine">
            @foreach ($mine as $c)
                <li><span><b>{{ $groups[$c->grp] ?? $c->grp }}</b> · {{ $c->q }}</span>
                    <form method="post" action="{{ $postUrl }}">@csrf<input type="hidden" name="delete" value="{{ $c->caseId }}"><button class="linkbtn" type="submit">Xóa</button></form></li>
            @endforeach
        </ul>@endif
    </div>
</div></body>
</html>
