<?php

namespace App\Services\Ai;

use RuntimeException;

/** Mô hình trả về thứ không đọc được thành JSON theo schema. */
final class SchemaViolation extends RuntimeException
{
}
