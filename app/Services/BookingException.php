<?php

namespace App\Services;

use RuntimeException;

/** Lỗi nghiệp vụ khi đặt tour (hết chỗ, tour đã ẩn, mã giảm giá sai...). Thông báo an toàn để hiện cho khách. */
class BookingException extends RuntimeException {}
