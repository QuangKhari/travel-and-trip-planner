@include('admin.blocks.header')
@php
    $pct = fn($a, $b) => $b ? round($a * 100 / $b, 1) . '%' : '—';
    $outcomeLabel = ['ok' => 'thành công', 'proposed' => 'đã đề xuất (chưa bấm)', 'applied' => 'người dùng áp dụng', 'skipped' => 'người dùng bỏ qua',
        'reply' => 'trả lời / hỏi lại', 'refused' => 'không tìm được đề xuất an toàn', 'guard_replaced' => 'guard thay câu', 'fallback' => 'chế độ cơ bản',
        'timeout' => 'quá giờ', 'error' => 'lỗi', 'invalid' => 'JSON sai'];
@endphp
<div class="container body"><div class="main_container">
@include('admin.blocks.sidebar')
<div class="right_col" role="main">
    <div class="page-title"><div class="title_left"><h3>Nhật ký <small>AI La bàn</small></h3></div>
        <div class="title_right text-right">@if ($has)<a class="btn btn-default" href="{{ route('admin.chat.ai.export', request()->query()) }}">Xuất CSV</a>@endif</div></div>
    <div class="clearfix"></div>
    @include('admin.chat.tabs', ['tab' => 'ai'])

    @if (!$has)
        <div class="alert alert-warning">Chưa có bảng <code>tbl_ai_runs</code>. Chạy <code>php artisan migrate</code>.</div>
    @else
    <div class="x_panel"><div class="x_title"><h2>Tổng hợp theo tính năng <small>theo bộ lọc bên dưới</small></h2><div class="clearfix"></div></div><div class="x_content">
        <table class="table table-bordered text-center" style="font-variant-numeric:tabular-nums">
            <thead><tr><th class="text-left">Tính năng</th><th>Lượt</th><th>Gọi AI</th><th>Chế độ cơ bản</th><th>Lỗi / quá giờ</th><th>Guard thay câu</th>
                <th>Tỷ lệ áp dụng<br><small>applied / (applied + skipped)</small></th><th>Không đề xuất được</th><th>TB / P95 (ms)</th><th>Token vào / ra</th><th>Chi phí ước tính</th></tr></thead>
            <tbody>
            @foreach ($stats as $f => $x) @php $r = $x['row']; @endphp
                <tr>
                    <td class="text-left"><b>{{ $x['label'] }}</b></td>
                    <td>{{ (int) $r->n }}</td>
                    <td>{{ $pct($r->llm, $r->n) }}</td>
                    <td>{{ $pct($r->fallback, $r->n) }}</td>
                    <td>{{ $pct($r->failed, $r->n) }}</td>
                    <td>{{ $f === 'copilot' ? '—' : (int) $r->guarded }}</td>
                    <td>@if ($f === 'copilot'){{ $x['accept'] !== null ? $x['accept'] . '%' : '—' }} <small class="text-muted">({{ (int) $r->applied }}/{{ (int) $r->applied + (int) $r->skipped }}, chờ {{ (int) $r->proposed }})</small>@else — @endif</td>
                    <td>{{ $f === 'copilot' ? (int) $r->refused : '—' }}</td>
                    <td>{{ $r->avgMs !== null ? round($r->avgMs) : '—' }} / {{ $x['p95'] ?? '—' }}</td>
                    <td>{{ number_format((int) $r->tin, 0, ',', '.') }} / {{ number_format((int) $r->tout, 0, ',', '.') }}</td>
                    <td>{{ $x['cost'] !== null ? '$' . number_format($x['cost'], 4) : 'đặt AI_PRICE_IN/OUT' }}</td>
                </tr>
            @endforeach
            </tbody>
        </table>
        <p class="text-muted small">"Gọi AI" = lượt có engine llm. "Chế độ cơ bản" = lùi về luật / câu mẫu (AI tắt, hết lượt, ngắt mạch, lỗi). Đề xuất Copilot luôn đã qua TripHealth: "không đề xuất được" là khi mọi phương án đều tạo lỗi cứng mới.</p>
    </div></div>

    <div class="x_panel"><div class="x_title"><h2>Kết quả theo loại</h2><div class="clearfix"></div></div><div class="x_content">
        <table class="table table-condensed"><thead><tr><th>Tính năng</th><th>Kết quả</th><th>Số lượt</th></tr></thead><tbody>
        @forelse ($byOutcome as $o)<tr><td>{{ $features[$o->feature] ?? $o->feature }}</td><td>{{ $outcomeLabel[$o->outcome] ?? $o->outcome }} <code>{{ $o->outcome }}</code></td><td>{{ $o->n }}</td></tr>
        @empty<tr><td colspan="3" class="text-muted">Chưa có lượt nào.</td></tr>@endforelse
        </tbody></table>
    </div></div>

    <div class="x_panel"><div class="x_content">
        <form method="GET" class="form-inline" style="margin-bottom:12px">
            <select name="feature" class="form-control"><option value="">Mọi tính năng</option>@foreach ($features as $k => $l)<option value="{{ $k }}" @selected($q['feature'] === $k)>{{ $l }}</option>@endforeach</select>
            <select name="outcome" class="form-control"><option value="">Mọi kết quả</option>@foreach ($outcomes as $k)<option value="{{ $k }}" @selected($q['outcome'] === $k)>{{ $outcomeLabel[$k] ?? $k }}</option>@endforeach</select>
            <select name="engine" class="form-control"><option value="">Mọi engine</option>@foreach (['llm', 'rules', 'template', 'cache'] as $k)<option value="{{ $k }}" @selected($q['engine'] === $k)>{{ $k }}</option>@endforeach</select>
            <input type="date" class="form-control" name="from" value="{{ $q['from'] }}"> – <input type="date" class="form-control" name="to" value="{{ $q['to'] }}">
            <button class="btn btn-default">Lọc</button>
        </form>
        <table class="table table-striped table-bordered"><thead><tr><th>Lúc</th><th>Tính năng</th><th>Kết quả</th><th>Engine · model · prompt</th><th>Chuyến</th><th>ms</th><th>Token</th><th>Cờ</th></tr></thead><tbody>
        @foreach ($rows as $r)<tr>
            <td><small>{{ $r->createdAt }}</small></td>
            <td>{{ $features[$r->feature] ?? $r->feature }}</td>
            <td>{{ $outcomeLabel[$r->outcome] ?? $r->outcome }}@if ($r->error)<br><small class="text-danger">{{ $r->error }}</small>@endif
                @if ($r->inputText)<details><summary class="text-muted">câu người dùng</summary>{{ $r->inputText }}</details>@endif</td>
            <td><small>{{ $r->engine }} · {{ $r->model ?? '—' }} · {{ $r->promptVersion }}</small></td>
            <td>@if ($r->tripId)#{{ $r->tripId }}@endif</td>
            <td>{{ $r->latencyMs }}</td>
            <td><small>{{ $r->inputTokens ?? '—' }} / {{ $r->outputTokens ?? '—' }}</small></td>
            <td><small><code>{{ $r->guardFlags }}</code></small></td>
        </tr>@endforeach
        </tbody></table>
        {{ $rows->links() }}
    </div></div>
    @endif
</div></div></div>
@include('admin.blocks.footer')
