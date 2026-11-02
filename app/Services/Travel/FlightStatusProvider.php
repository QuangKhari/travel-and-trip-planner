<?php

namespace App\Services\Travel;

/** T-37: một nguồn trạng thái chuyến bay. Trả null nếu không tìm thấy chuyến; ném lỗi nếu nhà cung cấp lỗi. */
interface FlightStatusProvider
{
    public function name(): string;

    public function configured(): bool;

    /** @return array{status:string,delayMinutes:?int}|null  status: scheduled|on_time|delayed|cancelled|departed|landed */
    public function status(string $flightNo, string $date): ?array;
}
