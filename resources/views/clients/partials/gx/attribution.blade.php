{{-- Dòng ghi nguồn, giấy phép và ghi công ảnh ở cuối nội dung. Biến: $row (sourceUrl, license, imageCredit, imageSourceUrl, coverImage).
     Chỉ nêu những gì có dữ liệu; liên kết chỉ nhận http(s). --}}
@php
    $isUrl = fn($u) => is_string($u) && preg_match('#^https?://#i', $u) === 1;
    $src = $isUrl($row->sourceUrl ?? null) ? $row->sourceUrl : null;
    $host = $src ? preg_replace('/^www\./i', '', (string) parse_url($src, PHP_URL_HOST)) : null;
    $license = trim((string) ($row->license ?? ''));
    $credit = !empty($row->coverImage) && !empty($row->imageCredit) ? trim((string) $row->imageCredit) : null;
    $creditUrl = $isUrl($row->imageSourceUrl ?? null) ? $row->imageSourceUrl : null;
@endphp
@if ($src || $license !== '' || $credit)
    <p class="gx-credit">
        @if ($src)
            Nguồn: <a href="{{ $src }}" rel="noopener nofollow" target="_blank">{{ $host ?: $src }}</a>
        @endif
        @if ($license !== '')
            {{ $src ? '·' : '' }} Giấy phép: {{ $license }}
        @endif
        @if ($credit)
            {{ $src || $license !== '' ? '·' : '' }} Ảnh:
            @if ($creditUrl)
                <a href="{{ $creditUrl }}" rel="noopener nofollow" target="_blank">{{ $credit }}</a>
            @else
                {{ $credit }}
            @endif
        @endif
    </p>
@endif
