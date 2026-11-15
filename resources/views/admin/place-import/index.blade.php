@extends('admin.layout')
@section('content')
    <div class="ad-head"><div><h1>Nhập địa điểm</h1>
        <p>Thêm nhiều địa điểm một lần từ tệp CSV / JSON. Hệ thống kiểm tra từng dòng (tọa độ, nhóm, nguồn, giờ mở cửa) và dò trùng với kho; chưa dòng nào vào kho cho tới khi có người duyệt.
            Dòng lệnh: <code>php artisan places:import tep.csv</code>.</p></div>
        <a class="ad-btn" href="{{ route('admin.place-import.template') }}"><i class="fa fa-download"></i> Tệp mẫu</a>
    </div>
    @if (!$ready)
        <div class="ad-card"><div class="ad-empty"><i class="fa fa-database"></i><b>Chưa chạy migration</b>Chạy <code>php artisan migrate</code>.</div></div>
    @else
        <section class="ad-card">
            <div class="ad-card__head"><h2>Nạp tệp</h2></div>
            <form action="{{ route('admin.place-import.store') }}" method="POST" enctype="multipart/form-data" class="ad-card__body ad-form ad-form--3" style="align-items:end">
                @csrf
                <div class="ad-fld"><label for="pfile">Tệp .csv hoặc .json <span class="req">*</span></label><input type="file" id="pfile" name="file" accept=".csv,.json,text/csv,application/json" required>
                    @error('file')<span class="ad-hint" style="color:var(--ad-bad,#dc2626)">{{ $message }}</span>@enderror</div>
                <div class="ad-fld"><label for="pnote">Ghi chú lô</label><input id="pnote" name="note" maxlength="255" placeholder="Ví dụ: khảo sát Đà Lạt 11/2026"></div>
                <div class="ad-fld"><button class="ad-btn ad-btn--primary" type="submit"><i class="fa fa-upload"></i> Nạp và kiểm tra</button></div>
            </form>
            <div class="ad-card__body ad-muted" style="padding-top:0;font-size:13px">
                Cột: <code>{{ implode(', ', \App\Services\Content\PlaceImport::COLUMNS) }}</code>. Bắt buộc: dest (mã điểm đến), name, category (sight / food), lat, lng, source.
                Giờ mở cửa theo dạng OpenStreetMap, ví dụ <code>Mo-Su 07:00-17:00</code> hoặc <code>Tu-Su 08:00-11:30,13:30-17:00; Mo off</code>.
            </div>
        </section>
        <section class="ad-card">
            <div class="ad-card__head"><h2>Các lô đã nạp</h2></div>
            @if ($batches->isEmpty())
                <div class="ad-empty"><i class="fa fa-inbox"></i><b>Chưa có lô nào</b>Tải tệp mẫu, điền dữ liệu rồi nạp ở trên.</div>
            @else
                <div class="ad-tablewrap"><table class="ad-table">
                    <thead><tr><th>Lô</th><th>Tệp</th><th>Người nạp</th><th class="num">Dòng</th><th class="num">Lỗi</th><th class="num">Trùng</th><th class="num">Chờ duyệt</th><th class="num">Đã vào kho</th><th></th></tr></thead>
                    <tbody>
                    @foreach ($batches as $b)
                        <tr><td>#{{ $b->batchId }}<br><small class="ad-muted">{{ \Carbon\Carbon::parse($b->createdAt)->format('d/m/Y H:i') }}</small></td>
                            <td>{{ $b->fileName }}@if ($b->note)<br><small class="ad-muted">{{ $b->note }}</small>@endif</td>
                            <td>{{ $b->byName ?: 'Dòng lệnh' }}</td>
                            <td class="num">{{ $b->total }}</td>
                            <td class="num">@if ($b->bad)<span class="ad-badge ad-badge--bad">{{ $b->bad }}</span>@else 0 @endif</td>
                            <td class="num">{{ $b->dup }}</td>
                            <td class="num">@if ($b->pending)<span class="ad-badge ad-badge--warn">{{ $b->pending }}</span>@else 0 @endif</td>
                            <td class="num">{{ $b->done }}</td>
                            <td class="num"><a class="ad-btn ad-btn--sm" href="{{ route('admin.place-import.show', $b->batchId) }}">Mở</a></td></tr>
                    @endforeach
                    </tbody>
                </table></div>
            @endif
        </section>
    @endif
@endsection
