@extends('admin.layout')
@section('content')
    @php $hm = fn($m) => $m === null ? '' : sprintf('%02d:%02d', intdiv($m, 60), $m % 60); @endphp
    <div class="ad-head"><div><h1>Báo giá tour riêng #{{ $q->quoteId }}</h1>
        <p>{{ $q->fullName ?: $q->username }} · {{ $q->email }} {{ $q->phoneNumber ? '· ' . $q->phoneNumber : '' }} · nhóm {{ $q->groupSize }} người · ngân sách {{ $q->budget ? number_format($q->budget, 0, ',', '.') . 'đ' : 'chưa nêu' }}</p></div>
        <a class="ad-btn ad-btn--ghost" href="{{ route('admin.tour-quotes') }}">← Danh sách</a></div>
    @if ($errors->any())<div class="ad-alert ad-alert--bad">{{ $errors->first() }}</div>@endif
    <div style="display:grid;grid-template-columns:minmax(0,3fr) minmax(0,2fr);gap:16px">
        <section class="ad-card">
            <div class="ad-card__head"><h2>{{ $snap['title'] ?? '' }} · {{ $snap['days'] ?? '' }} ngày @if (!empty($snap['startDate']))từ {{ \Carbon\Carbon::parse($snap['startDate'])->format('d/m/Y') }}@endif</h2></div>
            @if ($q->note)<p style="padding:0 16px"><b>Khách ghi:</b> {{ $q->note }}</p>@endif
            @foreach ($snap['plan'] ?? [] as $d)
                <div style="padding:6px 16px"><b>Ngày {{ $d['day'] }}</b>@if ($d['date']) · {{ \Carbon\Carbon::parse($d['date'])->format('d/m') }}@endif
                    <ul style="margin:4px 0 0">@forelse ($d['items'] as $i)<li>{{ $i['start'] !== null ? $hm($i['start']) . ' · ' : '' }}{{ $i['name'] }}</li>@empty<li class="ad-muted">trống</li>@endforelse</ul></div>
            @endforeach
        </section>
        <section class="ad-card">
            <div class="ad-card__head"><h2>{{ $q->status === 'open' ? 'Báo giá' : 'Đã báo giá (' . ['quoted' => 'chờ khách', 'accepted' => 'khách nhận', 'declined' => 'khách từ chối', 'cancelled' => 'đã hủy'][$q->status] . ')' }}</h2></div>
            @if (in_array($q->status, ['open', 'quoted'], true))
                <form method="POST" action="{{ route('admin.tour-quotes.quote', $q->quoteId) }}" class="ad-form" style="padding:0 16px 16px">@csrf
                    <div class="ad-fld"><label>Giá / người (đ)</label><input type="number" name="pricePerPerson" min="1000" required value="{{ old('pricePerPerson', $q->pricePerPerson) }}"></div>
                    <div class="ad-fld"><label>Bao gồm</label><textarea name="includes" rows="2" maxlength="600">{{ old('includes', $q->includes) }}</textarea></div>
                    <div class="ad-fld"><label>Không bao gồm</label><textarea name="excludes" rows="2" maxlength="600">{{ old('excludes', $q->excludes) }}</textarea></div>
                    <div class="ad-fld"><label>Lời nhắn cho khách</label><textarea name="message" rows="3" maxlength="1000">{{ old('message', $q->message) }}</textarea></div>
                    <div class="ad-fld"><label>Giữ giá tới</label><input type="date" name="validUntil" required value="{{ old('validUntil', $q->validUntil ?? now()->addDays(5)->toDateString()) }}"></div>
                    <button class="ad-btn ad-btn--primary" style="width:auto">Gửi báo giá</button>
                </form>
            @else
                <p style="padding:0 16px 16px">{{ number_format($q->pricePerPerson, 0, ',', '.') }}đ/người · tổng {{ number_format($q->totalPrice, 0, ',', '.') }}đ<br><small class="ad-muted">{{ $q->includes }}</small></p>
            @endif
        </section>
    </div>
@endsection
