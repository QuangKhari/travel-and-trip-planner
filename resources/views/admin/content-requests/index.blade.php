@include('admin.blocks.header')
<div class="container body">
    <div class="main_container">
        @include('admin.blocks.sidebar')

        <div class="right_col" role="main">
            @include('admin.partials.flash')
            <div class="ad-head">
                <div>
                    <h1>Nhu cầu chưa có dữ liệu</h1>
                    <p>Những nơi khách gõ vào Trip Planner mà Travela chưa có, xếp theo số lần. Viết nội dung cho nơi đứng đầu trước rồi đánh dấu đã xử lý. Không lưu ai đã tìm.</p>
                </div>
            </div>

            <div class="ad-card">
                <div class="ad-toolbar">
                    <div class="ad-chips">
                        <a class="ad-chip {{ !$all ? 'is-on' : '' }}" href="{{ route('admin.content-requests') }}">Chưa xử lý</a>
                        <a class="ad-chip {{ $all ? 'is-on' : '' }}" href="{{ route('admin.content-requests', ['all' => 1]) }}">Tất cả</a>
                    </div>
                </div>
                @if ($rows->count())
                    <div class="ad-tablewrap">
                        <table class="ad-table">
                            <thead><tr><th>Cụm khách gõ</th><th>Loại</th><th>Điểm đến Travela gần nhất</th><th class="num">Số lần</th><th>Lần gần nhất</th><th></th></tr></thead>
                            <tbody>
                            @foreach ($rows as $r)
                                <tr>
                                    <td><b>{{ $r->sample }}</b></td>
                                    <td>{{ $r->kind === 'destination' ? 'Điểm đến' : 'Địa điểm' }}</td>
                                    <td>{{ $r->nearestName ?: '—' }}</td>
                                    <td class="num">{{ $r->hits }}</td>
                                    <td>{{ \Illuminate\Support\Carbon::parse($r->lastAt)->format('d/m/Y H:i') }}</td>
                                    <td>
                                        <form method="POST" action="{{ route('admin.content-requests.resolve', ['id' => $r->requestId]) }}">
                                            @csrf
                                            <input type="hidden" name="on" value="{{ $r->resolved ? 0 : 1 }}">
                                            <button type="submit" class="btn btn-default btn-xs">{{ $r->resolved ? 'Mở lại' : 'Đã xử lý' }}</button>
                                        </form>
                                    </td>
                                </tr>
                            @endforeach
                            </tbody>
                        </table>
                    </div>
                @else
                    <p class="ad-note">Chưa có yêu cầu nào.</p>
                @endif
            </div>
        </div>
    </div>
</div>
@include('admin.blocks.footer')
