<?php

namespace App\Services\Planner\Health;

/**
 * Một nhóm ràng buộc của sức khỏe chuyến đi. Mỗi kiểm tra trả về:
 * ['group' => time|route|capacity|budget|context, 'level' => ok|warn|bad|info, 'code' => string, 'text' => string,
 *  'day' => ?int, 'index' => ?int (vị trí mục trong ngày), 'action' => ?string (optimize|balance|unfix|hours|rain|budget|place)]
 * Cách xử lý cụ thể (đề xuất có xem trước) do TripResolver dựng theo 'code'.
 */
interface Constraint
{
    public function group(): string;

    /** @return list<array<string,mixed>> */
    public function check(TripContext $ctx): array;
}
