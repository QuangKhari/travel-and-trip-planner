{{-- Đợt F: thanh nhiệm vụ buổi thử. Chỉ hiện với tài khoản người thử khi STUDY_ENABLED bật. --}}
@php $studyP = \App\Services\Study\StudySession::participant((int) session('userId')); @endphp
@if ($studyP)
    <div id="studyBar" class="stb" role="region" aria-label="Nhiệm vụ buổi thử"
        data-state="{{ route('study.state') }}" data-start="{{ route('study.start') }}" data-finish="{{ route('study.finish') }}"
        data-consent="{{ route('study.consent.form') }}" data-sus="{{ route('study.sus.form') }}" data-csrf="{{ csrf_token() }}"></div>
    <link rel="stylesheet" href="{{ asset('clients/assets/css/study.css') }}?v=1">
    <script src="{{ asset('clients/assets/js/study.js') }}?v=1" defer></script>
@endif
