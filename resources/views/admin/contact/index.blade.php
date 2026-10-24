@include('admin.blocks.header')
<div class="container body"><div class="main_container">
@include('admin.blocks.sidebar')
<div class="right_col" role="main">
    <div class="page-title"><div class="title_left"><h3>Hộp thư <small>liên hệ</small></h3></div></div>
    <div class="clearfix"></div>
    @if (session('success'))<div class="alert alert-success">{{ session('success') }}</div>@endif
    @if ($errors->any())<div class="alert alert-danger">@foreach ($errors->all() as $e)<div>{{ $e }}</div>@endforeach</div>@endif
    <div class="x_panel"><div class="x_content">
        <p>@foreach ($statuses as $k => $l)<a class="label label-{{ $k === 'new' ? 'danger' : 'default' }}" href="{{ route('admin.contact', ['status' => $k]) }}">{{ $l }}: {{ $counts[$k] ?? 0 }}</a> @endforeach
        <a href="{{ route('admin.contact', ['mail' => 'failed']) }}" class="label label-warning">Thư báo nhân viên chưa gửi được</a>
        <a href="{{ route('admin.contact') }}">Tất cả</a></p>
        <form method="GET" class="form-inline" style="margin-bottom:12px"><input class="form-control" name="q" value="{{ $search }}" placeholder="Tên, email, SĐT, nội dung"><button class="btn btn-default">Tìm</button></form>
        @forelse ($rows as $m)
            <div class="well" style="background:{{ $m->status === 'new' ? '#fff8e1' : '#fff' }}">
                <div><strong>{{ $m->name }}</strong> · {{ $m->phone }} · <a href="mailto:{{ $m->email }}">{{ $m->email }}</a>
                    <span class="label label-info">{{ $topics[$m->topic] ?? $m->topic }}</span>
                    @if ($m->bookingCode)<span class="label label-default">Mã đơn {{ $m->bookingCode }}</span>@endif
                    @unless ($m->mailSent)<span class="label label-warning" title="Thư báo cho nhân viên chưa gửi được, tin vẫn được lưu ở đây">chưa gửi mail</span>@endunless
                    <small class="text-muted pull-right">{{ \Illuminate\Support\Carbon::parse($m->createdAt)->format('d/m/Y H:i') }}</small></div>
                <p style="white-space:pre-wrap;margin-top:8px">{{ $m->message }}</p>
                <form method="POST" action="{{ route('admin.contact.update', $m->messageId) }}" class="form-inline">@csrf
                    <select name="status" class="form-control input-sm">@foreach ($statuses as $k => $l)<option value="{{ $k }}" @selected($m->status === $k)>{{ $l }}</option>@endforeach</select>
                    <input name="adminNote" class="form-control input-sm" style="width:360px" placeholder="Ghi chú nội bộ" value="{{ $m->adminNote }}">
                    <button class="btn btn-sm btn-primary">Lưu</button>
                    @if ($m->handledBy)<small class="text-muted">{{ $adminNames[$m->handledBy] ?? '' }} · {{ \Illuminate\Support\Carbon::parse($m->handledAt)->format('d/m H:i') }}</small>@endif
                </form>
                <form method="POST" action="{{ route('admin.contact.destroy', $m->messageId) }}" onsubmit="return confirm('Xóa tin này?')" style="margin-top:6px">@csrf<button class="btn btn-link btn-xs text-danger">Xóa</button></form>
            </div>
        @empty<p class="text-muted">Chưa có tin nhắn.</p>@endforelse
        {{ $rows->links() }}
    </div></div>
</div></div></div>
@include('admin.blocks.footer')
