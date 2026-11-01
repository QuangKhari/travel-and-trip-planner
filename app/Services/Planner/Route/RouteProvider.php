<?php

namespace App\Services\Planner\Route;

/**
 * Nguồn tính đoạn đường giữa hai điểm. Thay nguồn (Haversine -> OSRM / GraphHopper / Google Routes) không phải sửa Planner.
 */
interface RouteProvider
{
    /** Tên nguồn để ghi chú trên giao diện / API. */
    public function name(): string;

    /**
     * Ma trận đoạn đường giữa các điểm.
     *
     * @param array<int,array{0:float,1:float}> $pts [lat, lng]
     * @return array<int,array<int,array{km:float,min:int}>>
     */
    public function matrix(array $pts): array;
}
