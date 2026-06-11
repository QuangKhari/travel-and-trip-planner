<?php

namespace App\Models\admin;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use App\Models\admin\UserModel;
use App\Models\admin\ToursModel;

class Review extends Model
{
    use HasFactory;

    // Tên bảng trong database
    protected $table = 'tbl_reviews'; 

    // Khóa chính
    protected $primaryKey = 'reviewId';

    public $timestamps = false;

    protected $fillable = [
        'userId',
        'tourId',
        'rating',
        'comment',
    ];

    /**
     * Quan hệ tới User
     */
    public function user()
    {
         return $this->belongsTo(UserModel::class, 'userId', 'userId');

    }

    /**
     * Quan hệ tới Tour
     */
    public function tour()
    {
        return $this->belongsTo(ToursModel::class, 'tourId', 'tourId');
    }
}
