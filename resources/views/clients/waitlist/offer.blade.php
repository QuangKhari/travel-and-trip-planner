@include('clients.blocks.header')
@include('clients.support._style')
<main class="sp">
    <div class="sp-wrap">
        <header class="sp-head">
            <h1>Xác nhận lời mời từ danh sách chờ</h1>
            <p>Chỗ đang được giữ cho bạn đến <b>{{ date('H:i d/m/Y', strtotime($w->offerExpiresAt)) }}</b>. Xác nhận để đặt tour ngay.</p>
        </header>
        @if (session('error'))<div class="sp-alert" role="alert">{{ session('error') }}</div>@endif
        <section class="sp-card">
            <div class="sp-card__h"><h2>{{ $w->title }}</h2></div>
            <form class="sp-card__b sp-f" method="POST" action="{{ route('waitlist.accept', $w->offerToken) }}">
                @csrf
                <p>Khởi hành {{ date('d/m/Y', strtotime($w->departureDate)) }} · {{ $w->numAdults }} người lớn{{ $w->numChildren ? ', ' . $w->numChildren . ' trẻ em' : '' }} · tạm tính <b>{{ number_format($subtotal, 0, ',', '.') }} đ</b></p>
                @if ($errors->any())<div class="sp-err">{{ $errors->first() }}</div>@endif
                <div class="sp-2">
                    <div><label for="o-n">Họ và tên</label><input id="o-n" name="fullName" required maxlength="255" value="{{ old('fullName', $u->fullName ?? '') }}"></div>
                    <div><label for="o-e">Email</label><input id="o-e" type="email" name="email" required maxlength="50" value="{{ old('email', $u->email ?? '') }}"></div>
                </div>
                <div class="sp-2">
                    <div><label for="o-t">Số điện thoại</label><input id="o-t" name="tel" required pattern="[0-9]{10,11}" value="{{ old('tel', $u->phoneNumber ?? '') }}"></div>
                    <div><label for="o-a">Địa chỉ</label><input id="o-a" name="address" required maxlength="255" value="{{ old('address', $u->address ?? '') }}"></div>
                </div>
                <div class="sp-2">
                    <div><label for="o-p">Thanh toán</label>
                        <select id="o-p" name="payment">@foreach ($methods as $m)<option value="{{ $m }}" @selected(old('payment', 'office-payment') === $m)>{{ $m === 'office-payment' ? 'Tại văn phòng' : strtoupper($m) }}</option>@endforeach</select></div>
                    <div><label for="o-c">Mã giảm giá (nếu có)</label><input id="o-c" name="couponCode" maxlength="50" value="{{ old('couponCode') }}"></div>
                </div>
                <button class="sp-btn" type="submit">Xác nhận đặt tour</button>
            </form>
        </section>
    </div>
</main>
@include('clients.blocks.footer')
