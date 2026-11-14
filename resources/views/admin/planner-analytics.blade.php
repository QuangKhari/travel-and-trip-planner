@extends('admin.layout')
@section('content')
    @php
        $src = ['manual' => 'Tự tạo', 'generate' => 'Travela đề xuất', 'ai' => 'Từ một câu (La bàn)', 'chat' => 'Từ một câu (La bàn)', 'copy' => 'Nhân bản', 'community' => 'Từ Cộng đồng'];
        $feat = ['copilot' => 'La bàn sửa chuyến', 'brief' => 'Hiểu câu mô tả', 'explain' => 'Tóm tắt chuyến', 'ask' => 'Hỏi đáp', 'suggest' => 'Gợi ý chọn'];
        $ev = ['alert' => 'Cảnh báo mới', 'resolved' => 'Đã ổn', 'digest' => 'Bản tin', 'replan' => 'Xếp lại hôm nay'];
        $f = $d['funnel'];
    @endphp
    <div class="ad-head">
        <div>
            <h1>Phân tích Trip Planner</h1>
            <p>Số đếm thật trong {{ $d['days'] }} ngày gần nhất: chuyến được lên, chuyến dẫn tới đặt tour, tỷ lệ người dùng chấp nhận đề xuất của La bàn, cảnh báo đã gửi và điểm đến cần bổ sung dữ liệu nhất.</p>
        </div>
        <div style="display:flex;gap:6px">@foreach ([7, 30, 90, 365] as $n)<a class="ad-btn ad-btn--sm {{ $d['days'] === $n ? '' : 'ad-btn--ghost' }}" href="{{ route('admin.planner-analytics', ['days' => $n]) }}">{{ $n }} ngày</a>@endforeach</div>
    </div>

    <div class="ad-kpis" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(170px,1fr));gap:12px;margin-bottom:18px">
        <div class="ad-card" style="padding:14px"><small class="ad-muted">Chuyến mới</small><h2 style="margin:4px 0">{{ $d['trips']['made'] }}</h2><small>{{ $d['trips']['users'] }} người dùng</small></div>
        <div class="ad-card" style="padding:14px"><small class="ad-muted">Đang đi hôm nay / sắp đi (16 ngày)</small><h2 style="margin:4px 0">{{ $d['trips']['active'] }} / {{ $d['trips']['upcoming'] }}</h2><small>đang được theo dõi tự động</small></div>
        <div class="ad-card" style="padding:14px"><small class="ad-muted">Đơn tour đến từ Trip Planner</small><h2 style="margin:4px 0">{{ $f['fromTrip'] }}<small style="font-size:.9rem"> / {{ $f['all'] }} đơn</small></h2>
            <small>{{ $f['all'] ? round(100 * $f['fromTrip'] / $f['all']) : 0 }}% đơn · độ khớp TB {{ $f['score'] !== null ? $f['score'] . '%' : '—' }}</small></div>
        <div class="ad-card" style="padding:14px"><small class="ad-muted">Doanh thu đã thu từ các đơn đó</small><h2 style="margin:4px 0">{{ number_format($f['paid'], 0, ',', '.') }}đ</h2><small>{{ $f['live'] }} đơn chưa hủy</small></div>
    </div>

    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(320px,1fr));gap:16px">
        <section class="ad-card">
            <div class="ad-card__head"><h2>Chuyến theo cách tạo</h2></div>
            <table class="ad-table"><tbody>
                @forelse ($d['trips']['bySource'] as $k => $n)<tr><td>{{ $src[$k] ?? $k }}</td><td style="text-align:right">{{ $n }}</td></tr>
                @empty<tr><td class="ad-muted">Chưa có chuyến mới.</td></tr>@endforelse
            </tbody></table>
        </section>
        <section class="ad-card">
            <div class="ad-card__head"><h2>Theo dõi chuyến tự động</h2></div>
            <table class="ad-table"><tbody>
                @forelse ($d['watch'] as $k => $x)<tr><td>{{ $ev[$k] ?? $k }}</td><td style="text-align:right">{{ $x['n'] }} <small class="ad-muted">({{ $x['trips'] }} chuyến)</small></td></tr>
                @empty<tr><td class="ad-muted">Chưa có sự kiện. Kiểm tra <code>schedule:work</code> đang chạy.</td></tr>@endforelse
            </tbody></table>
        </section>
    </div>

    <section class="ad-card" style="margin-top:16px">
        <div class="ad-card__head"><h2>La bàn: đề xuất được chấp nhận</h2></div>
        <table class="ad-table">
            <thead><tr><th>Tính năng</th><th>Cách chạy</th><th>Lượt</th><th>Có đề xuất</th><th>Áp dụng / bỏ qua</th><th>Tỷ lệ chấp nhận</th><th>Lỗi / dự phòng</th><th>Độ trễ TB</th></tr></thead>
            <tbody>
            @forelse ($d['ai'] as $r)
                <tr><td>{{ $feat[$r['feature']] ?? $r['feature'] }}</td><td>{{ $r['engine'] === 'rules' ? 'Luật' : ($r['engine'] === 'llm' ? 'LLM' : $r['engine']) }}</td><td>{{ $r['n'] }}</td><td>{{ $r['proposed'] }}</td>
                    <td>{{ $r['applied'] }} / {{ $r['skipped'] }}</td><td><b>{{ $r['accept'] !== null ? $r['accept'] . '%' : '—' }}</b></td><td>{{ $r['failed'] }}</td><td>{{ number_format($r['ms'], 0, ',', '.') }} ms</td></tr>
            @empty<tr><td colspan="8" class="ad-muted">Chưa có lượt AI trong khoảng này.</td></tr>@endforelse
            </tbody>
        </table>
    </section>

    <section class="ad-card" style="margin-top:16px">
        <div class="ad-card__head"><h2>Điểm đến cần bổ sung dữ liệu nhất</h2>
            <a class="ad-btn ad-btn--sm ad-btn--ghost" href="{{ route('admin.place-data') }}">Báo sai chờ duyệt: {{ $d['reports'] }}</a></div>
        <p class="ad-muted" style="padding:0 16px">Xếp theo nhu cầu (số chuyến + lượt tìm chưa có kết quả) nhân với số chỗ thiếu. Ngưỡng "đủ" giống lệnh <code>php artisan planner:coverage</code>.</p>
        <table class="ad-table">
            <thead><tr><th>Điểm đến</th><th>Nhu cầu</th><th>Tham quan / ăn</th><th>Có giờ</th><th>Có giá</th><th>Còn thiếu</th></tr></thead>
            <tbody>
            @forelse ($d['gaps'] as $g)
                <tr><td><b>{{ $g['name'] }}</b></td><td>{{ $g['demand'] }}</td><td>{{ $g['sights'] }} / {{ $g['eat'] }}</td><td>{{ $g['hoursPct'] }}%</td><td>{{ $g['pricePct'] }}%</td>
                    <td>{!! $g['ok'] ? '<span class="ad-badge">đủ</span>' : e(implode(', ', $g['gaps'])) !!}</td></tr>
            @empty<tr><td colspan="6" class="ad-muted">Chưa có nhu cầu nào được ghi nhận.</td></tr>@endforelse
            </tbody>
        </table>
    </section>
@endsection
