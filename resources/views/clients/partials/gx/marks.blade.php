{{-- Nút đánh dấu (cần đăng nhập; travela-trip.js tự gắn xử lý theo [data-mark]).
     Biến: $refType (destination|food|event), $slug, $mark (saved|visited|tried|attended), $on (bool), $labels = [chữ khi tắt, chữ khi bật]. --}}
@php
    $on = (bool) ($on ?? false);
    $labels = array_values((array) ($labels ?? ['Đánh dấu', '✓ Đã đánh dấu']));
    $labelOff = $labels[0] ?? 'Đánh dấu';
    $labelOn = $labels[1] ?? '✓ ' . $labelOff;
@endphp
<button type="button" class="gx-btn2 {{ $on ? 'is-on' : '' }}" data-mark="{{ $mark }}" data-ref-type="{{ $refType }}"
    data-slug="{{ $slug }}" data-label-off="{{ $labelOff }}" data-label-on="{{ $labelOn }}"
    aria-pressed="{{ $on ? 'true' : 'false' }}"><span data-label>{{ $on ? $labelOn : $labelOff }}</span></button>
