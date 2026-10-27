@include('admin.blocks.header')
<div class="container body">
    <div class="main_container">
        @include('admin.blocks.sidebar')

        <div class="right_col" role="main">
            @include('admin.partials.flash')
            <div class="ad-head">
                <div>
                    <h1>Người dùng</h1>
                    <p>Tìm, kích hoạt, chặn hoặc khôi phục tài khoản khách hàng. Chặn = không đăng nhập/đặt tour được; Xóa = ẩn khỏi hệ thống (có thể khôi phục).</p>
                </div>
            </div>

            <div class="ad-card">
                <div class="ad-toolbar">
                    <form method="GET" action="{{ route('admin.users') }}" class="ad-search">
                        <i class="fa fa-search"></i>
                        <input type="search" name="q" value="{{ $q }}" placeholder="Tên, tên đăng nhập, email, số điện thoại…">
                        @if ($f !== 'all')<input type="hidden" name="f" value="{{ $f }}">@endif
                    </form>
                    <div class="ad-chips">
                        @foreach ($filters as $key => $label)
                            <a class="ad-chip {{ $f === $key ? 'is-on' : '' }}"
                                href="{{ route('admin.users', array_filter(['f' => $key === 'all' ? null : $key, 'q' => $q ?: null])) }}">{{ $label }} <b>{{ $counts[$key] }}</b></a>
                        @endforeach
                    </div>
                </div>

                @if ($users->count())
                    <div class="ad-tablewrap">
                        <table class="ad-table" id="ad-users">
                            <thead><tr><th>Người dùng</th><th>Liên hệ</th><th>Địa chỉ</th><th>Ngày đăng ký</th><th class="num">Đơn</th><th>Trạng thái</th><th></th></tr></thead>
                            <tbody>
                            @foreach ($users as $u)
                                <tr data-id="{{ $u->userId }}" data-active="{{ $u->isActive }}" data-status="{{ $u->status }}"
                                    data-url-active="{{ route('admin.active-user') }}" data-url-status="{{ route('admin.status-user') }}">
                                    <td>
                                        <div class="ad-person">
                                            <img src="{{ \App\Support\Avatar::url($u->avatar) }}" alt="" loading="lazy">
                                            <div><b>{{ $u->fullName ?: 'Chưa đặt tên' }}</b><small>{{ '@' . $u->username }}</small></div>
                                        </div>
                                    </td>
                                    <td>{{ $u->email }}<br><small class="ad-note">{{ $u->phoneNumber ?: '—' }}</small></td>
                                    <td>{{ $u->address ?: '—' }}</td>
                                    <td>{{ $u->createdDate ? \Illuminate\Support\Carbon::parse($u->createdDate)->format('d/m/Y') : '—' }}</td>
                                    <td class="num">{{ $bookingCount[$u->userId] ?? 0 }}</td>
                                    <td class="ad-u-badge"></td>
                                    <td><div class="ad-rowact ad-u-btns"></div></td>
                                </tr>
                            @endforeach
                            </tbody>
                        </table>
                    </div>
                    @if ($users->hasPages())
                        <div class="ad-pager"><span class="ad-note">Trang {{ $users->currentPage() }} / {{ $users->lastPage() }} · {{ $users->total() }} người dùng</span>{{ $users->links('admin.partials.pager') }}</div>
                    @endif
                @else
                    <div class="ad-empty"><i class="fa fa-users"></i>Không có người dùng nào{{ $q !== '' ? ' khớp “' . e($q) . '”' : '' }}.</div>
                @endif
            </div>
        </div>
    </div>
</div>
@push('scripts')
<script>
// Vẽ nhãn + nút theo trạng thái của từng dòng; thao tác bằng AJAX rồi vẽ lại, không tải lại trang.
(function () {
    var table = document.getElementById('ad-users');
    if (!table) return;
    var csrf = document.querySelector('meta[name="csrf-token"]').content;

    function paint(tr) {
        var st = tr.dataset.status || '', act = tr.dataset.active === 'y';
        var badge = st === 'b' ? ['bad', 'Bị chặn'] : st === 'd' ? ['mute', 'Đã xóa'] : act ? ['ok', 'Đang hoạt động'] : ['warn', 'Chưa kích hoạt'];
        tr.querySelector('.ad-u-badge').innerHTML = '<span class="ad-badge ad-badge--' + badge[0] + '">' + badge[1] + '</span>';
        var b = [];
        if (!st && !act) b.push(['activate', 'Kích hoạt', 'ad-btn--primary', '']);
        if (st === '') b.push(['b', 'Chặn', '', 'Chặn người dùng này? Họ sẽ không đăng nhập hay đặt tour được.']);
        if (st === 'b') b.push(['active', 'Bỏ chặn', '', '']);
        if (st !== 'd') b.push(['d', 'Xóa', 'ad-btn--danger', 'Xóa người dùng này? Có thể khôi phục lại sau.']);
        if (st === 'd') b.push(['active', 'Khôi phục', '', '']);
        tr.querySelector('.ad-u-btns').innerHTML = b.map(function (x) {
            return '<button type="button" class="ad-btn ad-btn--sm ' + x[2] + '" data-do="' + x[0] + '" data-msg="' + x[3] + '">' + x[1] + '</button>';
        }).join('');
    }

    function send(tr, what) {
        var isAct = what === 'activate';
        $.post(isAct ? tr.dataset.urlActive : tr.dataset.urlStatus,
            isAct ? { userId: tr.dataset.id, _token: csrf } : { userId: tr.dataset.id, status: what, _token: csrf })
            .done(function (r) {
                if (!r.success) { toastr.error(r.message); return; }
                if (isAct) tr.dataset.active = 'y'; else tr.dataset.status = r.status || '';
                paint(tr); toastr.success(r.message);
            })
            .fail(function (x) { toastr.error((x.responseJSON && x.responseJSON.message) || 'Có lỗi xảy ra. Vui lòng thử lại.'); });
    }

    table.addEventListener('click', function (ev) {
        var b = ev.target.closest('button[data-do]');
        if (!b) return;
        var tr = b.closest('tr'), what = b.dataset.do, msg = b.dataset.msg;
        if (!msg) return send(tr, what);
        Swal.fire({ title: 'Xác nhận', text: msg, icon: 'warning', showCancelButton: true, confirmButtonText: 'Đồng ý',
            cancelButtonText: 'Hủy', confirmButtonColor: '#0d9488', reverseButtons: true })
            .then(function (r) { if (r.isConfirmed) send(tr, what); });
    });
    table.querySelectorAll('tbody tr').forEach(paint);
})();
</script>
@endpush
@include('admin.blocks.footer')
