@extends('admin.layout')
@section('content')
    @php
        $tabs = ['pending' => 'Chờ duyệt', 'warn' => 'Cần xem', 'error' => 'Lỗi', 'dup' => 'Có thể trùng', 'done' => 'Đã xử lý', 'all' => 'Tất cả'];
        $stLabel = ['pending' => ['Chờ duyệt', 'ad-badge--warn'], 'created' => ['Đã tạo', 'ad-badge--ok'], 'merged' => ['Đã bổ sung', 'ad-badge--teal'], 'rejected' => ['Đã loại', 'ad-badge--mute']];
        $money = fn($v) => $v === null ? '' : ((float) $v == 0 ? 'Miễn phí' : number_format((float) $v, 0, ',', '.') . 'đ');
    @endphp
    <div class="ad-head"><div>
        <h1>Lô nhập #{{ $batch->batchId }} <small class="ad-muted" style="font-weight:400">{{ $batch->fileName }}</small></h1>
        <p>{{ $counts->total }} dòng. Duyệt: dòng mới tạo địa điểm ở trạng thái “đã duyệt” (chưa hiện cho khách) — tick “Hiển thị ngay” để công khai luôn; dòng trùng chỉ bổ sung các ô còn trống của địa điểm có sẵn. Dòng có lỗi phải sửa tệp và nạp lại.</p>
    </div><a class="ad-btn ad-btn--ghost" href="{{ route('admin.place-import') }}"><i class="fa fa-arrow-left"></i> Các lô</a></div>

    @if ($coverage)
        <section class="ad-card"><div class="ad-card__head"><h2>Điểm đến trong lô</h2><small>Đang hiển thị → nếu duyệt hết dòng mới hợp lệ</small></div>
            <div class="ad-tablewrap"><table class="ad-table"><thead><tr><th>Điểm đến</th><th class="num">Tham quan</th><th class="num">Ăn uống</th></tr></thead><tbody>
            @foreach ($coverage as $c)
                <tr><td>{{ $c['dest'] }}</td>
                    <td class="num">{{ $c['sight'] }}@if ($c['addSight']) → <b>{{ $c['sight'] + $c['addSight'] }}</b>@endif</td>
                    <td class="num">{{ $c['food'] }}@if ($c['addFood']) → <b>{{ $c['food'] + $c['addFood'] }}</b>@endif</td></tr>
            @endforeach
            </tbody></table></div>
        </section>
    @endif

    <nav style="display:flex;gap:6px;margin-bottom:12px;flex-wrap:wrap">
        @foreach ($tabs as $k => $l)
            <a class="ad-btn ad-btn--sm {{ $filter === $k ? 'ad-btn--primary' : 'ad-btn--ghost' }}" href="{{ route('admin.place-import.show', ['id' => $batch->batchId, 'loc' => $k]) }}">{{ $l }}@if ($k !== 'all') <span class="ad-badge">{{ (int) ($counts->$k ?? 0) }}</span>@endif</a>
        @endforeach
    </nav>

    <form method="POST" action="{{ route('admin.place-import.decide', $batch->batchId) }}" id="impForm">
        @csrf
        <section class="ad-card">
            @if ($rows->isEmpty())
                <div class="ad-empty"><i class="fa fa-check"></i><b>Không có dòng nào ở mục này</b></div>
            @else
                <div class="ad-card__head" style="gap:10px;flex-wrap:wrap">
                    <label style="display:flex;gap:6px;align-items:center;margin:0"><input type="checkbox" id="impAll"> Chọn các dòng duyệt được</label>
                    <span style="flex:1"></span>
                    <label style="display:flex;gap:6px;align-items:center;margin:0"><input type="checkbox" name="publish" value="1"> Hiển thị ngay cho khách</label>
                    <button class="ad-btn ad-btn--primary ad-btn--sm" name="action" value="approve" type="submit"><i class="fa fa-check"></i> Duyệt dòng đã chọn</button>
                    <input name="note" maxlength="255" placeholder="Lý do loại" style="max-width:220px" aria-label="Lý do loại">
                    <button class="ad-btn ad-btn--danger ad-btn--sm" name="action" value="reject" type="submit">Loại</button>
                </div>
                @if ($errors->any())<div class="ad-card__body" style="color:#dc2626;padding-bottom:0">{{ $errors->first() }}</div>@endif
                <div class="ad-tablewrap"><table class="ad-table">
                    <thead><tr><th></th><th>Dòng</th><th>Địa điểm</th><th>Chi tiết</th><th>Kiểm tra</th><th>Trạng thái</th></tr></thead>
                    <tbody>
                    @foreach ($rows as $r)
                        @php $d = json_decode($r->data, true); $iss = json_decode($r->issues, true) ?: []; $can = $r->status === 'pending' && $r->level !== 'error'; @endphp
                        <tr @if ($r->level === 'error') style="background:#fff5f5" @endif>
                            <td>@if ($can)<input type="checkbox" name="rows[]" value="{{ $r->rowId }}" class="imp-row" aria-label="Chọn dòng {{ $r->line }}">@endif</td>
                            <td class="num">{{ $r->line }}</td>
                            <td><b>{{ $d['name'] ?: '—' }}</b><br><small class="ad-muted">{{ $r->destName ?: 'không rõ điểm đến' }} · {{ $d['category'] === 'food' ? 'Ăn uống' : ($d['category'] === 'sight' ? 'Tham quan' : '?') }}{{ $d['kind'] ? ' · ' . \App\Support\PlaceKind::label($d['kind']) : '' }}</small>
                                @if ($d['lat'] !== null)<br><a class="ad-muted" style="font-size:12px" target="_blank" rel="noopener" href="https://www.openstreetmap.org/?mlat={{ $d['lat'] }}&mlon={{ $d['lng'] }}#map=17/{{ $d['lat'] }}/{{ $d['lng'] }}">{{ $d['lat'] }}, {{ $d['lng'] }} <i class="fa fa-external-link"></i></a>@endif</td>
                            <td style="font-size:13px;max-width:320px">
                                @if ($d['address'])<div>{{ $d['address'] }}</div>@endif
                                <div class="ad-muted">@if ($d['duration']){{ $d['duration'] }} phút · @endif @if ($d['priceMin'] !== null){{ $money($d['priceMin']) }}@if ($d['priceMax'] && $d['priceMax'] != $d['priceMin'])–{{ $money($d['priceMax']) }}@endif · @endif{{ $d['hours'] ?: 'chưa có giờ' }}</div>
                                <div class="ad-muted">Nguồn: {{ $d['source'] ?: '—' }}@if ($d['sourceUrl']) · <a href="{{ $d['sourceUrl'] }}" target="_blank" rel="noopener">liên kết</a>@endif</div>
                            </td>
                            <td style="font-size:13px;max-width:340px">
                                @forelse ($iss as $i)
                                    <div style="color:{{ $i['level'] === 'error' ? '#b42318' : '#92400e' }}"><i class="fa {{ $i['level'] === 'error' ? 'fa-times-circle' : 'fa-exclamation-triangle' }}"></i> {{ $i['msg'] }}</div>
                                @empty
                                    <span style="color:#15803d"><i class="fa fa-check"></i> Ổn</span>
                                @endforelse
                            </td>
                            <td>@php [$sl, $sc] = ($r->status === 'pending' && $r->level === 'error') ? ['Cần sửa tệp', 'ad-badge--bad'] : ($stLabel[$r->status] ?? [$r->status, '']); @endphp<span class="ad-badge {{ $sc }}">{{ $sl }}</span>
                                @if ($r->placeId)<br><small class="ad-muted">#{{ $r->placeId }}</small>@endif
                                @if ($r->note)<br><small class="ad-muted">{{ $r->note }}</small>@endif</td>
                        </tr>
                    @endforeach
                    </tbody>
                </table></div>
                <div class="ad-card__foot">{{ $rows->links() }}</div>
            @endif
        </section>
    </form>
@endsection
@push('scripts')
<script>
    (function () {
        var all = document.getElementById('impAll');
        if (all) all.addEventListener('change', function () { document.querySelectorAll('.imp-row').forEach(function (c) { c.checked = all.checked; }); });
    })();
</script>
@endpush
