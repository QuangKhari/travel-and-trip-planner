<?php

namespace App\Services;

use RuntimeException;

/** Lỗi do người dùng gây ra (file sai định dạng, quá lớn, vượt quota...) – an toàn để hiện thông báo. */
class InvalidImageException extends RuntimeException
{
}