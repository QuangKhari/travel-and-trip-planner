{{-- Nút đánh dấu. $refType, $slug, $mark: saved|visited|tried|attended, $on: bool, $labels: [chưa, đã]. Chưa đăng nhập: bấm sẽ chuyển tới trang đăng nhập. --}}
<button type="button" class="gx-btn2 {{ $on ? 'is-on' : '' }}" data-mark="{{ $mark }}" data-ref-type="{{ $refType }}"
    data-slug="{{ $slug }}" data-label-off="{{ $labels[0] }}" data-label-on="{{ $labels[1] }}"
    aria-pressed="{{ $on ? 'true' : 'false' }}"><span data-label>{{ $on ? $labels[1] : $labels[0] }}</span></button>
