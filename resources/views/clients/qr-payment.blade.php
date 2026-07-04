@include('clients.blocks.header')

<div class="container mt-5 text-center">

    <h3>Thanh toán chuyển khoản</h3>

    <p>Mã đơn hàng:</p>
    <h4>{{ $bookingCode }}</h4>

    <p>Số tiền:</p>
    <h4>{{ number_format($amount) }} VNĐ</h4>

    <p>Nội dung chuyển khoản:</p>
    <h4>{{ $bookingCode }}</h4>

    <img
        src="https://img.vietqr.io/image/MB-123456789-compact2.png?amount={{ $amount }}&addInfo={{ $bookingCode }}"
        width="350">

    <br><br>

    <p class="text-warning mt-3">
        Sau khi chuyển khoản thành công, vui lòng chụp ảnh biên lai và tải lên bên dưới để chúng tôi xác nhận nhanh hơn.
    </p>

    <!-- Form upload ảnh biên lai chuyển khoản -->
    <div class="row justify-content-center mt-4">
        <div class="col-md-5">
            <div id="proof-status" class="mb-3" style="display:none;"></div>

            <form id="transfer-proof-form" enctype="multipart/form-data">
                @csrf
                <div class="form-group text-left">
                    <label for="transferProof">Ảnh biên lai chuyển khoản <span class="text-danger">*</span></label>
                    <input type="file" name="transferProof" id="transferProof" accept="image/*" class="form-control-file" required>
                </div>

                <div class="text-center mb-3">
                    <img id="proof-preview" src="" alt="" style="display:none; max-width: 260px; border: 1px solid #ddd; border-radius: 6px; margin-top: 10px;">
                </div>

                <button type="submit" id="btn-upload-proof" class="btn btn-success btn-block">
                    Gửi ảnh chuyển khoản để xác nhận
                </button>
            </form>
        </div>
    </div>

    <div class="mt-4">
        <a href="{{ route('home') }}" class="btn btn-primary">
            Về trang chủ
        </a>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const fileInput = document.getElementById('transferProof');
    const preview = document.getElementById('proof-preview');
    const form = document.getElementById('transfer-proof-form');
    const statusBox = document.getElementById('proof-status');
    const submitBtn = document.getElementById('btn-upload-proof');

    fileInput.addEventListener('change', function () {
        const file = this.files[0];
        if (!file) return;

        const reader = new FileReader();
        reader.onload = function (e) {
            preview.src = e.target.result;
            preview.style.display = 'inline-block';
        };
        reader.readAsDataURL(file);
    });

    form.addEventListener('submit', function (e) {
        e.preventDefault();

        const formData = new FormData(form);
        const csrfToken = form.querySelector('input[name="_token"]').value;

        submitBtn.disabled = true;
        submitBtn.innerText = 'Đang gửi...';

        fetch('{{ route("booking.upload-transfer-proof") }}', {
            method: 'POST',
            headers: {
                'X-CSRF-TOKEN': csrfToken
            },
            body: formData
        })
        .then(res => res.json())
        .then(data => {
            statusBox.style.display = 'block';
            if (data.success) {
                statusBox.className = 'alert alert-success';
                statusBox.innerText = data.message;
                submitBtn.innerText = 'Đã gửi thành công';
            } else {
                statusBox.className = 'alert alert-danger';
                statusBox.innerText = data.message;
                submitBtn.disabled = false;
                submitBtn.innerText = 'Gửi ảnh chuyển khoản để xác nhận';
            }
        })
        .catch(() => {
            statusBox.style.display = 'block';
            statusBox.className = 'alert alert-danger';
            statusBox.innerText = 'Có lỗi xảy ra, vui lòng thử lại.';
            submitBtn.disabled = false;
            submitBtn.innerText = 'Gửi ảnh chuyển khoản để xác nhận';
        });
    });
});
</script>

@include('clients.blocks.footer')