@extends('admin.layout')
@php
    use App\Support\AuditLog;
    use App\Http\Controllers\admin\AuditLogController as AL;
    $roleLabel = ['admin' => 'Admin', 'manager' => 'Quản lý', 'staff' => 'Nhân viên'];
    $tone = fn($a) => str_contains($a, 'delete') || str_contains($a, 'cancel') || str_contains($a, 'block') ? 'bad'
        : (str_contains($a, 'create') || str_contains($a, 'publish') || str_contains($a, 'confirm') || str_contains($a, 'paid') || str_contains($a, 'done') ? 'ok'
        : (str_contains($a, 'refund') ? 'violet' : 'info'));
    $fmtVal = function ($v) {
        if (is_bool($v)) return $v ? 'có' : 'không';
        if ($v === null || $v === '') return '—';
        if (is_array($v)) return json_encode($v, JSON_UNESCAPED_UNICODE);
        return \Illuminate\Support\Str::limit((string) $v, 120);
    };
@endphp
@section('content')
    <div class="ad-head">
        <div>
            <h1>Nhật ký thao tác</h1>
            <p>Mọi thao tác quản trị quan trọng (đơn, hoàn tiền, tour, lịch khởi hành, khuyến mãi, người dùng, đánh giá, tài khoản quản trị) được ghi lại kèm người làm, thời điểm, IP và dữ liệu trước / sau. Nhật ký chỉ đọc. Hôm nay: <b>{{ $today }}</b> thao tác.</p>
        </div>
    </div>

    <div class="ad-card">
        <form class="ad-toolbar" method="GET" action="{{ route('admin.audit') }}">
            <div class="ad-search"><i class="fa fa-search"></i><input type="search" name="q" value="{{ $f['q'] ?? '' }}" placeholder="Tìm theo mô tả, mã đơn, mã đối tượng…" aria-label="Tìm"></div>
            <select name="entity" class="ad-field" aria-label="Loại đối tượng">
                <option value="">Mọi đối tượng</option>
                @foreach (AuditLog::ENTITIES as $k => $v)<option value="{{ $k }}" @selected(($f['entity'] ?? '') === $k)>{{ $v }}</option>@endforeach
            </select>
            <select name="actor" class="ad-field" aria-label="Người thao tác">
                <option value="">Mọi người</option>
                @foreach ($actors as $a)<option value="{{ $a->actorId }}" @selected((int) ($f['actor'] ?? 0) === (int) $a->actorId)>{{ $a->actorName ?? '#' . $a->actorId }}</option>@endforeach
            </select>
            <input type="date" name="from" class="ad-field" value="{{ $f['from'] ?? '' }}" aria-label="Từ ngày">
            <input type="date" name="to" class="ad-field" value="{{ $f['to'] ?? '' }}" aria-label="Đến ngày">
            <button class="ad-btn ad-btn--dark" type="submit"><i class="fa fa-filter"></i> Lọc</button>
            @if (array_filter($f))<a class="ad-btn ad-btn--ghost" href="{{ route('admin.audit') }}">Bỏ lọc</a>@endif
        </form>

        @if ($rows->count())
            <div class="ad-tablewrap">
                <table class="ad-table">
                    <thead><tr><th>Thời điểm</th><th>Người làm</th><th>Thao tác</th><th>Nội dung</th><th class="num">Chi tiết</th></tr></thead>
                    <tbody>
                    @foreach ($rows as $r)
                        @php $before = $r->before ? json_decode($r->before, true) : null; $after = $r->after ? json_decode($r->after, true) : null; $link = AL::link($r); @endphp
                        <tr>
                            <td class="nowrap">{{ date('H:i:s', strtotime($r->createdAt)) }}<span class="ad-sub">{{ date('d/m/Y', strtotime($r->createdAt)) }}</span></td>
                            <td class="nowrap"><b style="color:var(--ad-ink)">{{ $r->actorName ?? 'Hệ thống' }}</b><span class="ad-sub">{{ $roleLabel[$r->actorRole] ?? '' }}{{ $r->ip ? ' · ' . $r->ip : '' }}</span></td>
                            <td class="nowrap"><span class="ad-badge ad-badge--{{ $tone($r->action) }} ad-badge--plain">{{ AuditLog::label($r->action) }}</span>
                                <span class="ad-sub">{{ AuditLog::ENTITIES[$r->entity] ?? $r->entity }}{{ $r->entityId ? ' #' . $r->entityId : '' }}</span></td>
                            <td style="min-width:260px">{{ $r->summary }}
                                @if ($link)<a class="ad-sub" href="{{ $link }}">Mở {{ mb_strtolower(AuditLog::ENTITIES[$r->entity] ?? '') }} →</a>@endif</td>
                            <td class="num">
                                @if ($before || $after)
                                    <button type="button" class="ad-btn ad-btn--sm" data-toggle="collapse" data-target="#au-{{ $r->auditId }}" aria-expanded="false" aria-controls="au-{{ $r->auditId }}">Trước / sau</button>
                                @endif
                            </td>
                        </tr>
                        @if ($before || $after)
                            <tr class="collapse" id="au-{{ $r->auditId }}">
                                <td colspan="5" style="background:#fbfcfd">
                                    <table class="ad-diff">
                                        <thead><tr><th style="width:22%">Trường</th><th>Trước</th><th>Sau</th></tr></thead>
                                        <tbody>
                                        @foreach (array_unique(array_merge(array_keys($before ?? []), array_keys($after ?? []))) as $k)
                                            <tr><td><code class="ad-code">{{ $k }}</code></td>
                                                <td class="{{ $before !== null ? 'old' : '' }}">{{ $fmtVal($before[$k] ?? null) }}</td>
                                                <td class="{{ $after !== null ? 'new' : '' }}">{{ $fmtVal($after[$k] ?? null) }}</td></tr>
                                        @endforeach
                                        </tbody>
                                    </table>
                                </td>
                            </tr>
                        @endif
                    @endforeach
                    </tbody>
                </table>
            </div>
            @if ($rows->hasPages())<div class="ad-pager"><span class="ad-note">{{ $rows->total() }} dòng</span>{{ $rows->links('admin.partials.pager') }}</div>@endif
        @else
            <div class="ad-empty"><i class="fa fa-history"></i><b>Chưa có dòng nhật ký nào</b>Các thao tác quản trị từ nay sẽ được ghi tại đây.</div>
        @endif
    </div>
@endsection
