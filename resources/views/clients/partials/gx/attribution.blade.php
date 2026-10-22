{{-- Ghi công ảnh và nguồn nội dung. $row: object có imageCredit, imageSourceUrl, sourceUrl, license. --}}
@php
    $http = fn($u) => is_string($u) && preg_match('/^https?:\/\//i', $u) ? $u : null;
@endphp
@if (!empty($row->coverImage) && !empty($row->imageCredit))
    <p class="gx-credit">Ảnh:
        @if ($http($row->imageSourceUrl ?? null))
            <a href="{{ $http($row->imageSourceUrl) }}" target="_blank" rel="noopener noreferrer">{{ $row->imageCredit }}</a>
        @else
            {{ $row->imageCredit }}
        @endif
    </p>
@endif
@if (!empty($row->license) || $http($row->sourceUrl ?? null))
    <p class="gx-credit">Nội dung:
        @if ($http($row->sourceUrl ?? null))
            <a href="{{ $http($row->sourceUrl) }}" target="_blank" rel="noopener noreferrer">{{ $row->license ?: 'Nguồn' }}</a>
        @else
            {{ $row->license }}
        @endif
    </p>
@endif
