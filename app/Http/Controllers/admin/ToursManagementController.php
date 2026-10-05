<?php

namespace App\Http\Controllers\admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\admin\ToursModel;
use App\Services\InvalidImageException;
use App\Services\TourImageService;
use App\Support\TourImage;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use App\Support\HtmlSanitizer;


class ToursManagementController extends Controller
{
    private $tours;
    private TourImageService $images;

    public function __construct(TourImageService $images)
    {
        $this->tours = new ToursModel();
        $this->images = $images;
    }
    public function index()
    {
        $title = 'Quản lý Tours';

        $tours = $this->tours->getAllTours();
        return view('admin.tours', compact('title', 'tours'));
    }

    public function pageAddTours()
    {
        $title = 'Thêm Tours';

        return view('admin.add-tours', compact('title'));
    }

    public function addTours(Request $request)
    {
        $name = $request->input('name');
        $destination = $request->input('destination');
        $domain = $request->input('domain');
        $quantity = $request->input('number');
        $price_adult = $request->input('price_adult');
        $price_child = $request->input('price_child');
        $start_date = $request->input('start_date');
        $end_date = $request->input('end_date');
        $description = HtmlSanitizer::clean($request->input('description'));


        $startDate = Carbon::createFromFormat('d/m/Y', $start_date)->format('Y-m-d');
        $endDate = Carbon::createFromFormat('d/m/Y', $end_date)->format('Y-m-d');

        // Tính số ngày giữa start_date và end_date
        $days = Carbon::createFromFormat('Y-m-d', $startDate)->diffInDays(Carbon::createFromFormat('Y-m-d', $endDate));
        $nights = $days - 1;

        // Định dạng thời gian theo kiểu "X ngày Y đêm"
        $time = "{$days} ngày {$nights} đêm";


        $dataTours = [
            'title' => $name,
            'time' => $time,
            'description' => $description,
            'quantity' => $quantity,
            'priceAdult' => $price_adult,
            'priceChild' => $price_child,
            'destination' => $destination,
            'domain' => $domain,
            'availability' => 0,
            'startDate' => $startDate,
            'endDate' => $endDate
        ];
        //dd($dataTours);

        $createTour = $this->tours->createTours($dataTours);

        // dd($createTour);
        return response()->json([
            'success' => true,
            'message' => 'Tour added successfully!',
            'tourId' => $createTour
        ]);
    }

    public function addImagesTours(Request $request)
    {
        [$meta, $error] = $this->storeUploadedImage($request);
        if ($error) {
            return $error;
        }

        $tourId = (int) $request->tourId;
        try {
            $ok = DB::table('tbl_images')->insert([
                'tourId'      => $tourId,
                'imageURL'    => $meta['stem'],
                'width'       => $meta['width'],
                'height'      => $meta['height'],
                'sizeBytes'   => $meta['sizeBytes'],
                'sortOrder'   => (int) DB::table('tbl_images')->where('tourId', $tourId)->count(),
                'description' => pathinfo($request->file('image')->getClientOriginalName(), PATHINFO_FILENAME),
            ]);
        } catch (\Throwable $e) {
            report($e);
            return response()->json(['success' => false, 'message' => 'Không lưu được thông tin ảnh.'], 500);
        }

        if (!$ok) {
            return response()->json(['success' => false, 'message' => 'Không lưu được thông tin ảnh.'], 500);
        }

        return response()->json([
            'success' => true,
            'message' => 'Image uploaded successfully',
            'data'    => [
                'filename' => $meta['stem'],
                'url'      => TourImage::url($meta['stem'], 320),
                'tourId'   => $tourId,
            ],
        ], 200);
    }

    public function addTimeline(Request $request)
    {
        $tourId = $request->tourId;

        // Tạo một mảng chứa các timeline
        $timelines = [];

        // Lặp qua tất cả các keys trong request để tìm các cặp `day-X` và `itinerary-X`
        foreach ($request->all() as $key => $value) {
            if (preg_match('/^day-(\d+)$/', $key, $matches)) {
                $dayNumber = $matches[1]; // Lấy số ngày (X) từ `day-X`

                // Tìm `itinerary-X` tương ứng
                $itineraryKey = "itinerary-{$dayNumber}";
                if ($request->has($itineraryKey)) {
                    $timelines[] = [
                        'tourId' => $tourId,
                        'title' => $value,
                        'description' => HtmlSanitizer::clean($request->input($itineraryKey)),
                    ];
                }
            }
        }

        foreach ($timelines as $timeline) {
            $this->tours->addTimeLine($timeline);
        }

        // Chuyển ảnh đã upload tạm ở Bước 2 (gửi lên qua images[]) sang bảng chính thức tbl_images
        $images = $request->input('images');
        if ($images && is_array($images)) {
            $position = 0;
            foreach ($images as $image) {
                if (!$this->images->isStem($image)) {
                    continue; // chỉ nhận đường dẫn do hệ thống sinh ra, bỏ qua giá trị lạ
                }
                $dataUpload = [
                    'tourId' => $tourId,
                    'imageURL' => $image,
                    'description' => ''
                ] + $this->images->describe($image);
                $dataUpload['sortOrder'] = $position++;
                $this->tours->uploadImages($dataUpload);
            }
        }

        $dataUpdate = [
            'availability' => 1
        ];

        $updateAvailability = $this->tours->updateTour($tourId, $dataUpdate);
        toastr()->success('Thêm tour thành công!');
        return redirect()->route('admin.page-add-tours');
    }
    public function getTourEdit(Request $request)
    {
        $tourId = $request->tourId;

        $tour = $this->tours->getTour($tourId);
        $images = $this->tours->getImages($tourId)->map(function ($image) {
            $image->thumbUrl = TourImage::url($image->imageURL, 320);
            return $image;
        });
        $timeline = $this->tours->getTimeLine($tourId);

        return response()->json([
            'success' => true,
            'tour' => $tour,
            'images' => $images,
            'timeline' => $timeline
        ]);
    }

    public function uploadTempImagesTours(Request $request)
    {
        [$meta, $error] = $this->storeUploadedImage($request);
        if ($error) {
            return $error;
        }

        $tourId = (int) $request->tourId;
        try {
            $this->tours->uploadTempImages([
                'tourId'       => $tourId,
                'imageTempURL' => $meta['stem'],
            ]);
        } catch (\Throwable $e) {
            report($e);
            return response()->json(['success' => false, 'message' => 'Không lưu được thông tin ảnh tạm.'], 500);
        }

        return response()->json([
            'success' => true,
            'message' => 'Image uploaded successfully',
            'data'    => [
                'filename' => $meta['stem'],
                'url'      => TourImage::url($meta['stem'], 320),
                'tourId'   => $tourId,
            ],
        ], 200);
    }

    /**
     * Kiểm tra request + lưu ảnh qua TourImageService.
     *
     * @return array{0:?array,1:?\Illuminate\Http\JsonResponse} [meta, lỗi]
     */

    private function storeUploadedImage(Request $request): array
    {
        // File vượt upload_max_filesize / post_max_size của PHP: báo đúng nguyên nhân thay vì "Chưa chọn ảnh"
        $uploaded = $request->file('image');
        if ($uploaded && !$uploaded->isValid()) {
            return [null, response()->json([
                'success' => false,
                'message' => 'Tải ảnh thất bại: ' . $uploaded->getErrorMessage(),
            ], 422)];
        }
        if (!$request->hasFile('image') && $request->server('CONTENT_LENGTH') && empty($request->all())) {
            return [null, response()->json([
                'success' => false,
                'message' => 'Ảnh vượt giới hạn dung lượng của PHP (post_max_size). Hãy tăng giới hạn trong php.ini.',
            ], 422)];
        }

        $validator = Validator::make($request->all(), [
            'tourId' => ['required', 'integer', 'min:1'],
            'image'  => ['required', 'file'],
        ], [
            'tourId.required' => 'Thiếu mã tour.',
            'image.required'  => 'Chưa chọn ảnh.',
        ]);

        if ($validator->fails()) {
            return [null, response()->json(['success' => false, 'message' => $validator->errors()->first()], 422)];
        }

        $tourId = (int) $request->input('tourId');
        if (!DB::table('tbl_tours')->where('tourId', $tourId)->exists()) {
            return [null, response()->json(['success' => false, 'message' => 'Tour không tồn tại.'], 404)];
        }

        try {
            return [$this->images->storeUploaded($request->file('image'), $tourId), null];
        } catch (InvalidImageException $e) {
            return [null, response()->json(['success' => false, 'message' => $e->getMessage()], 422)];
        } catch (\Throwable $e) {
            report($e);
            return [null, response()->json(['success' => false, 'message' => 'Không xử lý được ảnh. Thử lại hoặc chọn ảnh khác.'], 500)];
        }
    }
    public function updateTour(Request $request)
    {
        $tourId = $request->tourId;
        $name = $request->input('name');
        $destination = $request->input('destination');
        $domain = $request->input('domain');
        $quantity = $request->input('number');
        $price_adult = $request->input('price_adult');
        $price_child = $request->input('price_child');
        $description = HtmlSanitizer::clean($request->input('description'));

        $dataTours = [
            'title'       => $name,
            'description' => $description,
            'quantity'    => $quantity,
            'priceAdult'  => $price_adult,
            'priceChild'  => $price_child,
            'destination' => $destination,
            'domain'      => $domain,
        ];

        // Ảnh đang có trước khi sửa (để biết ảnh nào bị gỡ và cần xóa file sau khi lưu thành công)
        $oldStems = $this->tours->getImages($tourId)->pluck('imageURL')->all();

        $images = $request->input('images');  // Mảng đường dẫn ảnh (stem) gửi lên từ form
        // Chấp nhận đường dẫn mới (tours/{id}/{hash}) hoặc tên file cũ trơn (chưa chạy media:migrate-tour-images); loại giá trị lạ.
        $images = is_array($images)
            ? array_values(array_unique(array_filter($images, fn($i) => is_string($i) && $i !== '' && ($this->images->isStem($i) || $i === basename($i)))))
            : [];
        $timelines = $request->input('timeline');

        DB::transaction(function () use ($tourId, $dataTours, $images, $name, $timelines) {
            $this->tours->deleteData($tourId, 'tbl_timeline');
            $this->tours->deleteData($tourId, 'tbl_images');

            $this->tours->updateTour($tourId, $dataTours);

            foreach ($images as $position => $image) {
                $this->tours->uploadImages([
                    'tourId'      => $tourId,
                    'imageURL'    => $image,
                    'description' => $name,
                    'sortOrder'   => $position,
                    'isCover'     => $position === 0 ? 1 : 0,
                ] + $this->images->describe($image));
            }

            if ($timelines && is_array($timelines)) {
                foreach ($timelines as $timeline) {
                    $this->tours->addTimeLine([
                        'tourId'      => $tourId,
                        'title'       => $timeline['title'],
                        'description' => HtmlSanitizer::clean($timeline['itinerary']),
                    ]);
                }
            }
        });

        // Chỉ xóa file vật lý SAU KHI transaction thành công (chính sách §6.5)
        $this->images->deleteMany(array_diff($oldStems, $images));

        return response()->json([
            'success' => true,
            'message' => 'Sửa thành công!',
        ]);
    }

    public function deleteTour(Request $request)
    {
        $tourId = $request->tourId;

        $result = $this->tours->deleteTour($tourId);
        $tours = $this->tours->getAllTours();
        // Kiểm tra kết quả trả về từ Model
        if ($result['success']) {
            if (empty($result['hidden'])) {
                $this->images->deleteTourDirectory((int) $tourId);
            }   // xóa ảnh vật lý sau khi DB đã xóa thành công
            return response()->json([
                'success' => true,
                'message' => $result['message'],
                'data' => view('admin.partials.list-tours', compact('tours'))->render()
            ]);
        } else {
            return response()->json([
                'success' => false,
                'message' => $result['message']
            ]);
        }
    }
}
