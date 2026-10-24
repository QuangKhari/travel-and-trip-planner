@include('admin.blocks.header')
@php $pct = fn($n) => $s->total ? round($n * 100 / $s->total, 1) . '%' : '—'; @endphp
<div class="container body"><div class="main_container">
@include('admin.blocks.sidebar')
<div class="right_col" role="main">
    <div class="page-title"><div class="title_left"><h3>Nhật ký <small>Hỏi đáp</small></h3></div>
        <div class="title_right text-right"><a class="btn btn-default" href="{{ route('admin.chat.export', request()->query()) }}">Xuất CSV</a></div></div>
    <div class="clearfix"></div>
    <div class="x_panel"><div class="x_content">
        <div class="row text-center">
            <div class="col-md-2"><h3>{{ $s->total }}</h3>câu hỏi</div>
            <div class="col-md-2"><h3>{{ $pct($s->cited) }}</h3>có dẫn nguồn</div>
            <div class="col-md-2"><h3>{{ $pct($s->refused) }}</h3>bị từ chối</div>
            <div class="col-md-2"><h3>{{ $pct($s->cached) }}</h3>từ bộ nhớ đệm</div>
            <div class="col-md-2"><h3>{{ $s->errors }}</h3>lỗi</div>
            <div class="col-md-2"><h3>{{ round($s->avgMs ?? 0) }} / {{ $p95 ?? '—' }} ms</h3>TB / P95</div>
        </div>
    </div></div>

    <div class="x_panel"><div class="x_title"><h2>Câu hỏi bị từ chối nhiều nhất (nội dung còn thiếu)</h2><div class="clearfix"></div></div><div class="x_content">
        <table class="table table-condensed"><tbody>@forelse ($topRefused as $r)<tr><td>{{ $r->question }}</td><td>{{ $r->n }} lần</td><td><small>{{ $r->last }}</small></td></tr>@empty<tr><td class="text-muted">Chưa có.</td></tr>@endforelse</tbody></table>
    </div></div>

    <div class="x_panel"><div class="x_content">
        <form method="GET" class="form-inline" style="margin-bottom:12px">
            <select name="f" class="form-control">@foreach (['' => 'Tất cả', 'refused' => 'Bị từ chối', 'uncited' => 'Trả lời không dẫn nguồn', 'error' => 'Có lỗi', 'cached' => 'Từ bộ nhớ đệm'] as $k => $l)<option value="{{ $k }}" @selected($f === $k)>{{ $l }}</option>@endforeach</select>
            <input class="form-control" name="q" value="{{ $search }}" placeholder="Tìm câu hỏi">
            <input type="date" class="form-control" name="from" value="{{ $from }}"> – <input type="date" class="form-control" name="to" value="{{ $to }}">
            <button class="btn btn-default">Lọc</button>
        </form>
        <table class="table table-striped table-bordered"><thead><tr><th>Lúc</th><th>Câu hỏi</th><th>Kết quả</th><th>Engine</th><th>ms</th></tr></thead><tbody>
        @foreach ($rows as $r)<tr>
            <td><small>{{ $r->createdAt }}</small></td>
            <td>{{ $r->question }}@if ($r->answer)<details><summary class="text-muted">xem trả lời</summary><div style="white-space:pre-wrap">{{ $r->answer }}</div></details>@endif</td>
            <td>@if ($r->refused)<span class="label label-warning">từ chối</span>@elseif ($r->cited)<span class="label label-success">dẫn nguồn</span>@else<span class="label label-default">không dẫn nguồn</span>@endif
                @if ($r->cached)<span class="label label-info">cache</span>@endif @if ($r->error)<span class="label label-danger" title="{{ $r->error }}">lỗi</span>@endif</td>
            <td>{{ $r->engine }}</td><td>{{ $r->latencyMs }}</td></tr>@endforeach
        </tbody></table>
        {{ $rows->links() }}
    </div></div>
</div></div></div>
@include('admin.blocks.footer')
