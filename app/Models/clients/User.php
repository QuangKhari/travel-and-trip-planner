<?php

namespace App\Models\clients;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Support\Facades\DB;

class User extends Model
{
    protected $table = 'tbl_users';


    public function getUserId($username)
    {
        return DB::table($this->table)
            ->select('userId')
            ->where('username', $username)->value('userId');
    }
    public function getUser($id)
    {
        $users = DB::table($this->table)
            ->where('userId', $id)->first();

        return $users;
    }

    public function updateUser($id, $data)
    {
        $update = DB::table($this->table)
            ->where('userid', $id)
            ->update($data);

        return $update;
    }

    // Email này đã có tài khoản KHÁC dùng chưa? (không tính chính mình)
    public function emailTakenByOther($email, $userId): bool
    {
        return DB::table($this->table)
            ->where('email', $email)
            ->where('userId', '!=', $userId)
            ->exists();
    }

    public function getMyTours($id)
    {
        $myTours = DB::table('tbl_booking')
            ->join('tbl_tours', 'tbl_booking.tourId', '=', 'tbl_tours.tourId')
            ->leftJoin('tbl_checkout', 'tbl_booking.bookingId', '=', 'tbl_checkout.bookingId')
            ->where('tbl_booking.userId', $id)
            ->orderByDesc('tbl_booking.bookingDate')
            ->select(
                'tbl_booking.*',
                'tbl_tours.title',
                'tbl_tours.time',
                'tbl_tours.destination',
                'tbl_tours.startDate',
                'tbl_tours.endDate',
                'tbl_checkout.checkoutId',
                'tbl_checkout.paymentMethod',
                'tbl_checkout.paymentStatus'
            )
            ->get();

        $tourIds = $myTours->pluck('tourId')->unique();

        $ratings = DB::table('tbl_reviews')
            ->where('userId', $id)
            ->whereIn('tourId', $tourIds)
            ->pluck('rating', 'tourId');

        $images = DB::table('tbl_images')
            ->whereIn('tourId', $tourIds)
            ->get()
            ->groupBy('tourId');

        foreach ($myTours as $tour) {
            $tour->rating = $ratings[$tour->tourId] ?? 0;
            $tour->images = ($images[$tour->tourId] ?? collect())->pluck('imageURL');
        }

        return $myTours;
    }
}
