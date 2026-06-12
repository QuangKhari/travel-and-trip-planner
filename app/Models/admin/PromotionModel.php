<?php

namespace App\Models\admin;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class PromotionModel extends Model
{
     use HasFactory;

    protected $table = 'tbl_promotion';

    /**
     * Lấy toàn bộ promotion
     */
    public function getPromotion()
    {
        return DB::table($this->table)
            ->orderByDesc('promotionId')
            ->get();
    }

    /**
     * Lấy promotion theo ID
     */
    public function getPromotionById($promotionId)
    {
        return DB::table($this->table)
            ->where('promotionId', $promotionId)
            ->first();
    }

    /**
     * Thêm promotion
     */
    public function addPromotion($data)
    {
        return DB::table($this->table)
            ->insertGetId($data);
    }

    /**
     * Cập nhật promotion
     */
    public function updatePromotion($promotionId, $data)
    {
        return DB::table($this->table)
            ->where('promotionId', $promotionId)
            ->update($data);
    }

    /**
     * Xóa promotion
     */
    public function deletePromotion($promotionId)
    {
        return DB::table($this->table)
            ->where('promotionId', $promotionId)
            ->delete();
    }

    /**
     * Tìm voucher theo code
     */
    public function getPromotionByCode($code)
    {
        return DB::table($this->table)
            ->where('code', $code)
            ->first();
    }

    /**
     * Giảm số lượng voucher sau khi sử dụng
     */
    public function decreaseQuantity($promotionId)
    {
        return DB::table($this->table)
            ->where('promotionId', $promotionId)
            ->decrement('quantity', 1);
    }
    
}
