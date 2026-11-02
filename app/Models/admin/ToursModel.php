<?php

namespace App\Models\admin;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Support\Facades\DB;

class ToursModel extends Model
{
    use HasFactory;

    protected $table = 'tbl_tours';
    protected $primaryKey = 'tourId';
    public $timestamps = false;

    public function getAllTours()
    {
        $today = now()->toDateString();
        $q = DB::table($this->table . ' as t')
            ->leftJoin('tbl_destinations as d', 'd.destinationId', '=', 't.destinationId')
            ->select('t.*', 'd.name as destName')
            ->selectSub(fn($s) => $s->from('tbl_images')->whereColumn('tbl_images.tourId', 't.tourId')->orderByDesc('isCover')->orderBy('sortOrder')->limit(1)->select('imageURL'), 'cover')
            ->selectSub(fn($s) => $s->from('tbl_booking')->whereColumn('tbl_booking.tourId', 't.tourId')->whereIn('bookingStatus', ['n', 'y'])->selectRaw('COUNT(*)'), 'activeBookings')
            ->orderBy('t.tourId', 'DESC');
        if (\Illuminate\Support\Facades\Schema::hasTable('tbl_tour_availability')) {
            $q->selectSub(fn($s) => $s->from('tbl_tour_availability')->whereColumn('tbl_tour_availability.tourId', 't.tourId')
                ->where('departureDate', '>', $today)->where('status', 'open')->selectRaw('COUNT(*)'), 'upcomingDeps');
        }

        return $q->get();
    }

    public function createTours($data)
    {
        return DB::table($this->table)->insertGetId($data);
    }

    public function uploadImages($data)
    {
        return DB::table('tbl_images')->insert($data);
    }

    public function uploadTempImages($data)
    {
        return DB::table('tbl_temp_images')->insert($data);
    }

    public function addTimeLine($data)
    {
        return DB::table('tbl_timeline')->insert($data);
    }

    public function updateTour($tourId, $data)
    {
        $updated = DB::table($this->table)
            ->where('tourId', $tourId)
            ->update($data);

        return $updated;
    }
    public function deleteTour($tourId)
    {
        // Tour đã có booking / đánh giá / lịch sử thì không xóa hẳn (khóa ngoại RESTRICT), chỉ ẩn khỏi trang người dùng
        $hasRelated = DB::table('tbl_booking')->where('tourId', $tourId)->exists()
            || DB::table('tbl_reviews')->where('tourId', $tourId)->exists()
            || DB::table('tbl_history')->where('tourId', $tourId)->exists();

        if ($hasRelated) {
            DB::table($this->table)->where('tourId', $tourId)->update(['availability' => 0]);
            return [
                'success' => true,
                'hidden'  => true,
                'message' => 'Tour đã có booking/đánh giá nên không thể xóa hẳn. Tour đã được ẩn khỏi trang người dùng.',
            ];
        }

        try {
            DB::transaction(function () use ($tourId) {
                DB::table('tbl_timeline')->where('tourId', $tourId)->delete();
                DB::table('tbl_images')->where('tourId', $tourId)->delete();
                DB::table('tbl_temp_images')->where('tourId', $tourId)->delete();

                $deleted = DB::table($this->table)->where('tourId', $tourId)->delete();
                if (!$deleted) {
                    throw new \RuntimeException('Tour không tồn tại.');
                }
            });
            return ['success' => true, 'hidden' => false, 'message' => 'Tour đã được xóa thành công.'];
        } catch (\Throwable $e) {
            report($e);
            return ['success' => false, 'message' => 'Không thể xóa tour lúc này. Vui lòng thử lại sau.'];
        }
    }

    public function getTour($tourId)
    {
        return DB::table($this->table)->where('tourId', $tourId)->first();
    }

    public function getImages($tourId)
    {
        return DB::table('tbl_images')->where('tourId', $tourId)->get();
    }

    public function getTimeLine($tourId)
    {
        return DB::table('tbl_timeline')->where('tourId', $tourId)->get();
    }

    public function deleteData($tourId, $tbl)
    {
        return DB::table($tbl)->where('tourId', $tourId)->delete();
    }
}
