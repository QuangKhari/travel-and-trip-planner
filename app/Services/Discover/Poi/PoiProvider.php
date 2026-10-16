<?php

namespace App\Services\Discover\Poi;

interface PoiProvider
{
    /** Tên nguồn lưu ở cột tbl_external_places.source */
    public function name(): string;

    /**
     * @return array<int,array{externalId:string,name:string,category:string,kind:?string,address:?string,lat:float,lng:float,rank:int,website:?string}>
     *         category: see | culture | eat
     */
    public function fetch(float $lat, float $lng, int $radiusM): array;
}
