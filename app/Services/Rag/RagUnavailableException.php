<?php

namespace App\Services\Rag;

use RuntimeException;

/** Chưa có chỉ mục (chưa chạy rag:build, hoặc đổi mô hình nhúng mà chưa nhúng lại). */
class RagUnavailableException extends RuntimeException
{
}
