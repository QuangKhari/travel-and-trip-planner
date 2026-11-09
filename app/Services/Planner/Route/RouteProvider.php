<?php

namespace App\Services\Planner\Route;

/**
 * Nguồn tính đoạn đường giữa hai điểm. Thay nguồn (Haversine -> OSRM / GraphHopper / Google Routes) không phải sửa Planner.
 */
interface RouteProvider
{
    /** Tên nguồn để ghi chú trên giao diện / API. */
    public function name(): string;

    /** Trạng thái một đoạn đường (chất lượng số liệu, không phải "đi được hay không" theo ý người dùng). */
    public const VERIFIED = 'verified';       // dịch vụ định tuyến trả tuyến thật
    public const ESTIMATED = 'estimated';     // ước tính (đường chim bay × hệ số), chưa xác minh
    public const UNREACHABLE = 'unreachable'; // dịch vụ định tuyến khẳng định KHÔNG có đường (km/min chỉ là ước tính tham khảo)
    public const UNKNOWN = 'unknown';         // tọa độ không hợp lệ / thiếu: không tính được

    /**
     * Ma trận đoạn đường giữa các điểm. Mỗi ô có status (một trong các hằng trên):
     * UNREACHABLE và UNKNOWN KHÔNG được hiểu là "0 phút"; nơi dùng phải cảnh báo.
     *
     * @param array<int,array{0:float,1:float}> $pts [lat, lng]
     * @return array<int,array<int,array{km:float,min:int,status:string}>>
     */
    public function matrix(array $pts): array;
}
