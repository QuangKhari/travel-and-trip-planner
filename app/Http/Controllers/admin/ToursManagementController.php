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
use App\Services\UnsafeHtmlException;


class ToursManagementController extends Controller
{
    private $tours;
    private TourImageService $images;
    private const MIN_IMAGES = 5;   // số ảnh tối thiểu để đăng một tour

    public function __construct(TourImageService $images)
    {
        parent::__construct();
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

    /**
     * Validate + chuẩn hóa thông tin cơ bản của tour (Bước 1 wizard).
     * Dùng chung cho tạo mới (addTours) và sửa bản nháp (updateBasicTour).
     *
     * @return array{0:?array,1:?\Illuminate\Http\JsonResponse} [dữ liệu ghi vào tbl_tours, phản hồi lỗi]
     */
    private function parseBasicTourInput(Request $request): array
    {
        $validator = Validator::make($request->all(), [
            'name'        => 'required|string|max:255',
            'destination' => 'required|string|max:255',
            'domain'      => 'required|in:b,t,n',
            'number'      => 'required|integer|min:1|max:100000',
            'price_adult' => 'required|numeric|min:0|max:999999999999',
            'price_child' => 'required|numeric|min:0|max:999999999999',
            'start_date'  => 'required|date_format:d/m/Y',
            'end_date'    => 'required|date_format:d/m/Y',
            'description' => 'nullable|string|max:20000',
        ], [
            'name.required'        => 'Vui lòng nhập tên tour.',
            'destination.required' => 'Vui lòng nhập điểm đến.',
            'domain.required'      => 'Vui lòng chọn khu vực.',
            'domain.in'            => 'Khu vực không hợp lệ.',
            'number.required'      => 'Vui lòng nhập số lượng.',
            'number.integer'       => 'Số lượng phải là số nguyên.',
            'number.min'           => 'Số lượng phải từ 1 trở lên.',
            'price_adult.required' => 'Vui lòng nhập giá người lớn.',
            'price_adult.numeric'  => 'Giá người lớn không hợp lệ.',
            'price_adult.min'      => 'Giá người lớn không được âm.',
            'price_child.required' => 'Vui lòng nhập giá trẻ em.',
            'price_child.numeric'  => 'Giá trẻ em không hợp lệ.',
            'price_child.min'      => 'Giá trẻ em không được âm.',
            'start_date.required'  => 'Vui lòng chọn ngày bắt đầu.',
            'start_date.date_format' => 'Ngày bắt đầu phải có dạng ngày/tháng/năm.',
            'end_date.required'    => 'Vui lòng chọn ngày kết thúc.',
            'end_date.date_format' => 'Ngày kết thúc phải có dạng ngày/tháng/năm.',
            'description.required' => 'Vui lòng điền mô tả.',
            'price_adult.max' => 'Giá người lớn vượt quá giới hạn cho phép.',
            'price_child.max' => 'Giá trẻ em vượt quá giới hạn cho phép.',
        ]);

        // HTTP 200 + success=false: JS của wizard chỉ hiện message của server khi nhận 200
        if ($validator->fails()) {
            return [null, response()->json(['success' => false, 'message' => $validator->errors()->first()])];
        }

        $start = Carbon::createFromFormat('d/m/Y', $request->input('start_date'))->startOfDay();
        $end   = Carbon::createFromFormat('d/m/Y', $request->input('end_date'))->startOfDay();

        if ($end->lt($start)) {
            return [null, response()->json(['success' => false, 'message' => 'Ngày kết thúc phải sau hoặc bằng ngày bắt đầu.'])];
        }

        // Ngày đầu và ngày cuối đều tính
        $days   = (int) $start->diffInDays($end) + 1;
        $nights = $days - 1;

        try {
            $description = HtmlSanitizer::cleanOrReject($request->input('description'));
        } catch (UnsafeHtmlException $e) {
            return [null, response()->json(['success' => false, 'message' => $e->getMessage()])];
        }

        return [[
            'title'       => $request->input('name'),
            'time'        => "{$days} ngày {$nights} đêm",
            'description' => $description,
            'quantity'    => (int) $request->input('number'),
            'priceAdult'  => $request->input('price_adult'),
            'priceChild'  => $request->input('price_child'),
            'destination' => $request->input('destination'),
            'domain'      => $request->input('domain'),
            'startDate'   => $start->format('Y-m-d'),
            'endDate'     => $end->format('Y-m-d'),
        ], null];
    }

    public function addTours(Request $request)
    {
        [$fields, $error] = $this->parseBasicTourInput($request);
        if ($error) {
            return $error;
        }

        $createTour = $this->tours->createTours($fields + [
            'availability' => 0,    // chỉ được bật ở bước cuối, khi đã đủ ảnh (xem Bước 3)
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Tour added successfully!',
            'tourId'  => $createTour,
        ]);
    }

    public function addTimeline(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'tourId' => 'required|integer|min:1|exists:tbl_tours,tourId',
        ], [
            'tourId.required' => 'Thiếu mã tour.',
            'tourId.integer'  => 'Mã tour không hợp lệ.',
            'tourId.min'      => 'Mã tour không hợp lệ.',
            'tourId.exists'   => 'Không tìm thấy tour.',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => $validator->errors()->first(),
            ], 422);
        }

        $tourId = (int) $request->input('tourId');

        $timelines = [];

        foreach ($request->all() as $key => $value) {
            if (preg_match('/^day-(\d+)$/', $key, $matches) && $request->has("itinerary-{$matches[1]}")) {
                if (!is_string($value) || trim($value) === '' || mb_strlen($value) > 255) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Tiêu đề ngày không hợp lệ hoặc vượt quá 255 ký tự.',
                    ], 422);
                }

                $itinerary = $request->input("itinerary-{$matches[1]}");

                if (!is_string($itinerary)) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Nội dung lộ trình không hợp lệ.',
                    ], 422);
                }

                if (mb_strlen($itinerary) > 20000) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Nội dung lộ trình không được vượt quá 20000 ký tự.',
                    ], 422);
                }

                try {
                    $description = HtmlSanitizer::cleanOrReject($itinerary);
                } catch (UnsafeHtmlException $e) {
                    return response()->json(['success' => false, 'message' => $e->getMessage()]);
                }

                $timelines[(int) $matches[1]] = [
                    'tourId'      => $tourId,
                    'title'       => trim($value),
                    'description' => $description,
                ];
            }
        }

        ksort($timelines);

        if (empty($timelines)) {
            return response()->json([
                'success' => false,
                'message' => 'Vui lòng nhập lộ trình cho tour. Tour vẫn đang ở trạng thái ẩn.'
            ], 422);
        }

        $timelineDays = array_keys($timelines);

        foreach ($timelineDays as $index => $day) {
            if ($day !== $index + 1) {
                return response()->json([
                    'success' => false,
                    'message' => 'Ngày trong lộ trình phải bắt đầu từ ngày 1 và liên tục.'
                ], 422);
            }
        }

        try {
            $result = DB::transaction(function () use ($tourId, $timelines) {
                // Khóa tour trong transaction để tránh hai request cùng lúc thêm tour.
                $tour = DB::table('tbl_tours')
                    ->where('tourId', $tourId)
                    ->lockForUpdate()
                    ->first();

                if (!$tour) {
                    return [
                        'success' => false,
                        'status'  => 404,
                        'message' => 'Không tìm thấy tour.',
                    ];
                }

                if ((int) $tour->availability === 1) {
                    return [
                        'success' => false,
                        'status'  => 409,
                        'message' => 'Tour này đã được thêm trước đó.',
                    ];
                }

                if ($tour->startDate <= now()->toDateString()) {
                    return [
                        'success' => false,
                        'status'  => 422,
                        'message' => 'Ngày khởi hành phải sau ngày hiện tại. Tour vẫn đang ở trạng thái ẩn.',
                    ];
                }

                if ((int) $tour->quantity <= 0) {
                    return [
                        'success' => false,
                        'status'  => 422,
                        'message' => 'Số lượng tour phải lớn hơn 0. Tour vẫn đang ở trạng thái ẩn.',
                    ];
                }

                // Số ngày tối đa của timeline = số ngày thực tế của tour.
                $start = Carbon::parse($tour->startDate)->startOfDay();
                $end = Carbon::parse($tour->endDate)->startOfDay();
                $maxTimelineDays = (int) $start->diffInDays($end) + 1;

                if (count($timelines) !== $maxTimelineDays || max(array_keys($timelines)) !== $maxTimelineDays) {
                    return [
                        'success' => false,
                        'status'  => 422,
                        'message' => "Lộ trình phải có đúng {$maxTimelineDays} ngày.",
                    ];
                }

                // Lấy ảnh tạm trực tiếp từ DB và chỉ nhận ảnh thuộc đúng tour.
                $images = DB::table('tbl_temp_images')
                    ->where('tourId', $tourId)
                    ->orderBy('imageId')
                    ->pluck('imageTempURL')
                    ->filter(fn($i) => is_string($i) && $this->images->isStemForTour($i, $tourId) && $this->images->stemExists($i))
                    ->unique()
                    ->values()
                    ->all();

                if (count($images) < self::MIN_IMAGES) {
                    return [
                        'success' => false,
                        'status'  => 422,
                        'message' => 'Cần ít nhất ' . self::MIN_IMAGES . ' hình ảnh hợp lệ để đăng tour. Tour vẫn đang ở trạng thái ẩn.',
                    ];
                }

                foreach ($timelines as $timeline) {
                    $this->tours->addTimeLine($timeline);
                }

                foreach ($images as $position => $image) {
                    $this->tours->uploadImages([
                        'tourId'      => $tourId,
                        'imageURL'    => $image,
                        'description' => '',
                        'sortOrder'   => $position,
                        'isCover'     => $position === 0 ? 1 : 0,
                    ] + $this->images->describe($image));
                }

                DB::table('tbl_temp_images')
                    ->where('tourId', $tourId)
                    ->delete();

                $this->tours->updateTour($tourId, ['availability' => 1]);

                return [
                    'success' => true,
                    'status'  => 200,
                    'message' => 'Thêm tour thành công!',
                ];
            });

            if (!$result['success']) {
                return response()->json([
                    'success' => false,
                    'message' => $result['message'],
                ], $result['status']);
            }

            return response()->json([
                'success'  => true,
                'message'  => $result['message'],
                'redirect' => route('admin.page-add-tours'),
            ]);
        } catch (\Throwable $e) {
            report($e);

            return response()->json([
                'success' => false,
                'message' => 'Không thể hoàn tất tour. Vui lòng thử lại.',
            ], 500);
        }
    }

    public function getTourEdit(Request $request)
    {
        $tourId = (int) $request->tourId;

        $tour = $this->tours->getTour($tourId);

        // Id lạ: 404 thay vì trả `tour: null` (L-E-14)
        if (!$tour) {
            return response()->json(['success' => false, 'message' => 'Không tìm thấy tour.'], 404);
        }

        $images = $this->tours->getImages($tourId)->map(function ($image) {
            $image->thumbUrl = TourImage::url($image->imageURL, 320);
            return $image;
        });
        $timeline = $this->tours->getTimeLine($tourId);

        return response()->json([
            'success'  => true,
            'tour'     => $tour,
            'images'   => $images,
            'timeline' => $timeline,
        ]);
    }

    public function uploadTempImagesTours(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'tourId' => 'required|integer|min:1',
        ], [
            'tourId.required' => 'Thiếu mã tour.',
            'tourId.integer'  => 'Mã tour không hợp lệ.',
            'tourId.min'      => 'Mã tour không hợp lệ.',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => $validator->errors()->first(),
            ], 422);
        }

        $tourId = (int) $request->input('tourId');

        try {
            $result = DB::transaction(function () use ($request, $tourId) {
                $tour = DB::table('tbl_tours')
                    ->where('tourId', $tourId)
                    ->lockForUpdate()
                    ->first();

                if (!$tour) {
                    return [
                        'response' => response()->json([
                            'success' => false,
                            'message' => 'Tour không tồn tại.'
                        ], 404),
                    ];
                }

                if ((int) $tour->availability === 1) {
                    return [
                        'response' => response()->json([
                            'success' => false,
                            'message' => 'Tour đã đăng, không thể thêm ảnh tạm.'
                        ], 409),
                    ];
                }

                [$meta, $error] = $this->storeUploadedImage($request);

                if ($error) {
                    return [
                        'response' => $error,
                    ];
                }

                try {
                    $this->tours->uploadTempImages([
                        'tourId'       => $tourId,
                        'imageTempURL' => $meta['stem'],
                    ]);
                } catch (\Throwable $e) {
                    // DB insert thất bại thì xóa ảnh vật lý vừa tạo,
                    // tránh ảnh mồ côi trong storage.
                    $this->images->delete($meta['stem']);
                    throw $e;
                }

                return [
                    'meta' => $meta,
                ];
            });

            if (isset($result['response'])) {
                return $result['response'];
            }

            $meta = $result['meta'];

            return response()->json([
                'success' => true,
                'message' => 'Image uploaded successfully',
                'data'    => [
                    'filename' => $meta['stem'],
                    'url'      => TourImage::url($meta['stem'], 320),
                    'tourId'   => $tourId,
                ],
            ], 200);
        } catch (\Throwable $e) {
            report($e);

            return response()->json([
                'success' => false,
                'message' => 'Không lưu được thông tin ảnh tạm.'
            ], 500);
        }
    }

    /**
     * Kiểm tra request + lưu ảnh qua TourImageService.
     *
     * @return array{0:?array,1:?\Illuminate\Http\JsonResponse} [meta, lỗi]
     */

    /**
     * Upload ảnh từ modal "Sửa tour" (tour đã đăng). Chỉ lưu file, KHÔNG ghi tbl_temp_images:
     * ảnh chỉ được ghi vào tbl_images khi bấm Lưu (updateTour), ảnh bỏ dở do lệnh media:cleanup-orphans dọn.
     */
    public function uploadTourImage(Request $request)
    {
        [$meta, $error] = $this->storeUploadedImage($request);

        if ($error) {
            return $error;
        }

        return response()->json([
            'success' => true,
            'message' => 'Image uploaded successfully',
            'data'    => [
                'filename' => $meta['stem'],
                'url'      => TourImage::url($meta['stem'], 320),
                'tourId'   => (int) $request->input('tourId'),
            ],
        ]);
    }

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
        $validator = Validator::make($request->all(), [
            'tourId'      => 'required|integer|exists:tbl_tours,tourId',
            'name'        => 'required|string|max:255',
            'destination' => 'required|string|max:255',
            'domain'      => 'required|in:b,t,n',
            'number'      => 'required|integer|min:0|max:100000',
            'original_number' => 'nullable|integer|min:0',
            'price_adult' => 'required|numeric|min:0|max:999999999999',
            'price_child' => 'required|numeric|min:0|max:999999999999',
            'start_date'  => 'required|date_format:d/m/Y',
            'end_date'    => 'required|date_format:d/m/Y',
            'description' => 'nullable|string|max:20000',
            'images'      => 'nullable|array',
            'timeline'    => 'nullable|array',
        ], [
            'tourId.exists'        => 'Không tìm thấy tour.',
            'name.required'        => 'Vui lòng nhập tên tour.',
            'destination.required' => 'Vui lòng nhập điểm đến.',
            'domain.in'            => 'Khu vực không hợp lệ.',
            'number.integer'       => 'Số lượng phải là số nguyên.',
            'number.min'           => 'Số lượng không được âm.',
            'price_adult.min'      => 'Giá người lớn không được âm.',
            'price_child.min'      => 'Giá trẻ em không được âm.',
            'start_date.required'    => 'Vui lòng chọn ngày bắt đầu.',
            'start_date.date_format' => 'Ngày bắt đầu phải có dạng ngày/tháng/năm.',
            'end_date.required'      => 'Vui lòng chọn ngày kết thúc.',
            'end_date.date_format'   => 'Ngày kết thúc phải có dạng ngày/tháng/năm.',
            'price_adult.max'      => 'Giá người lớn vượt quá giới hạn cho phép.',
            'price_child.max'      => 'Giá trẻ em vượt quá giới hạn cho phép.',
        ]);

        // HTTP 200 + success=false để JS hiện đúng thông báo
        if ($validator->fails()) {
            return response()->json(['success' => false, 'message' => $validator->errors()->first()]);
        }

        $tourId = (int) $request->tourId;
        $name = $request->input('name');

        $start = Carbon::createFromFormat('d/m/Y', $request->input('start_date'))->startOfDay();
        $end   = Carbon::createFromFormat('d/m/Y', $request->input('end_date'))->startOfDay();

        if ($end->lt($start)) {
            return response()->json([
                'success' => false,
                'message' => 'Ngày kết thúc phải sau hoặc bằng ngày bắt đầu.',
            ]);
        }

        $days = (int) $start->diffInDays($end) + 1;
        $nights = $days - 1;

        try {
            $description = HtmlSanitizer::cleanOrReject($request->input('description'));
        } catch (UnsafeHtmlException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()]);
        }

        $dataTours = [
            'title'       => $name,
            'description' => $description,
            'quantity'    => (int) $request->input('number'),
            'priceAdult'  => $request->input('price_adult'),
            'priceChild'  => $request->input('price_child'),
            'destination' => $request->input('destination'),
            'domain'      => $request->input('domain'),
            'startDate'   => $start->format('Y-m-d'),
            'endDate'     => $end->format('Y-m-d'),
            'time'        => "{$days} ngày {$nights} đêm",
        ];

        $images = $request->input('images');
        $images = is_array($images)
            ? array_values(array_unique(array_filter($images, fn($i) => is_string($i) && $i !== '')))
            : [];
        $timelines = $request->input('timeline');

        if (!is_array($timelines) || count($timelines) !== $days) {
            return response()->json([
                'success' => false,
                'message' => "Lộ trình phải có đúng {$days} ngày.",
            ], 422);
        }

        foreach ($timelines as &$timeline) {
            if (!is_array($timeline)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Dữ liệu lộ trình không hợp lệ.',
                ], 422);
            }

            $title = $timeline['title'] ?? '';
            $itinerary = $timeline['itinerary'] ?? '';

            if (!is_string($title) || mb_strlen($title) > 255) {
                return response()->json([
                    'success' => false,
                    'message' => 'Tiêu đề ngày trong lộ trình không hợp lệ.',
                ], 422);
            }

            if (!is_string($itinerary)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Nội dung lộ trình không hợp lệ.',
                ], 422);
            }

            if (mb_strlen($itinerary) > 20000) {
                return response()->json([
                    'success' => false,
                    'message' => 'Nội dung lộ trình không được vượt quá 20000 ký tự.',
                ], 422);
            }

            try {
                $timeline['itinerary'] = HtmlSanitizer::cleanOrReject($itinerary);
            } catch (UnsafeHtmlException $e) {
                return response()->json([
                    'success' => false,
                    'message' => $e->getMessage(),
                ]);
            }
        }
        unset($timeline);

        if (count($images) > 0 && count($images) < self::MIN_IMAGES) {
            return response()->json([
                'success' => false,
                'message' => 'Tour phải có ít nhất ' . self::MIN_IMAGES . ' hình ảnh.'
            ], 422);
        }

        // Thiếu dữ liệu = giữ nguyên
        $replaceImages   = count($images) > 0;
        $replaceTimeline = is_array($timelines) && count($timelines) > 0;

        $result = DB::transaction(function () use ($request, $tourId, $dataTours, $images, $name, $timelines, $replaceImages, $replaceTimeline) {
            $tour = DB::table('tbl_tours')
                ->where('tourId', $tourId)
                ->lockForUpdate()
                ->first();

            if (!$tour) {
                return [
                    'success' => false,
                    'status' => 404,
                    'message' => 'Không tìm thấy tour.',
                ];
            }

            // Số chỗ có thể đã đổi từ lúc admin mở form (khách vừa đặt/hủy)
            $originalNumber = $request->input('original_number');
            if ($originalNumber !== null && $originalNumber !== '' && (int) $originalNumber !== (int) $tour->quantity) {
                if ((int) $dataTours['quantity'] === (int) $originalNumber) {
                    // Admin không sửa số chỗ: giữ nguyên số chỗ hiện tại, không ghi đè bằng giá trị cũ
                    $dataTours['quantity'] = (int) $tour->quantity;
                } else {
                    return [
                        'success' => false,
                        'status'  => 409,
                        'message' => 'Số chỗ còn lại đã thay đổi từ khi bạn mở form (có đơn mới hoặc đơn hủy). Hãy mở lại form rồi sửa.',
                    ];
                }
            }

            if (
                (int) $tour->availability === 1
                && (int) $dataTours['quantity'] < (int) $tour->quantity
                && DB::table('tbl_booking')
                ->where('tourId', $tourId)
                ->where('bookingStatus', '!=', 'c')
                ->exists()
            ) {
                return [
                    'success' => false,
                    'status' => 409,
                    'message' => 'Tour đã có booking nên không thể giảm số chỗ còn lại.',
                ];
            }

            $oldStems = $this->tours->getImages($tourId)->pluck('imageURL')->all();

            $validImages = array_values(array_unique(array_filter(
                $images,
                fn($i) => is_string($i)
                    && $i !== ''
                    && (
                        ($this->images->isStemForTour($i, $tourId) && $this->images->stemExists($i))
                        || (!$this->images->isStem($i) && in_array($i, $oldStems, true))
                    )
            )));

            if (count($validImages) !== count($images)) {
                return [
                    'success' => false,
                    'status' => 422,
                    'message' => 'Danh sách hình ảnh không hợp lệ hoặc ảnh đã không còn tồn tại.',
                ];
            }

            $images = $validImages;

            $dateChanged =
                $tour->startDate !== $dataTours['startDate']
                || $tour->endDate !== $dataTours['endDate'];

            if ($dateChanged && DB::table('tbl_booking')->where('tourId', $tourId)->exists()) {
                return [
                    'success' => false,
                    'status' => 409,
                    'message' => 'Tour đã có lịch sử booking nên không thể thay đổi ngày khởi hành.',
                ];
            }

            $this->tours->updateTour($tourId, $dataTours);

            if ($replaceImages) {
                $this->tours->deleteData($tourId, 'tbl_images');

                foreach ($images as $position => $image) {
                    $this->tours->uploadImages([
                        'tourId'      => $tourId,
                        'imageURL'    => $image,
                        'description' => $name,
                        'sortOrder'   => $position,
                        'isCover'     => $position === 0 ? 1 : 0,
                    ] + $this->images->describe($image));
                }
            }

            if ($replaceTimeline) {
                $this->tours->deleteData($tourId, 'tbl_timeline');

                foreach ($timelines as $timeline) {
                    $this->tours->addTimeLine([
                        'tourId'      => $tourId,
                        'title'       => $timeline['title'] ?? '',
                        'description' => $timeline['itinerary'] ?? '',
                    ]);
                }
            }

            return [
                'success' => true,
                'status' => 200,
                'oldStems' => $oldStems,
            ];
        });

        if (!$result['success']) {
            return response()->json([
                'success' => false,
                'message' => $result['message'],
            ], $result['status']);
        }

        // Chỉ xóa file vật lý SAU KHI transaction thành công, và chỉ khi ảnh được thay
        if ($replaceImages) {
            $this->images->deleteMany(array_diff($result['oldStems'], $images));
        }

        $tours = $this->tours->getAllTours();

        return response()->json([
            'success' => true,
            'message' => 'Sửa tour thành công!',
            'data' => view('admin.partials.list-tours', compact('tours'))->render(),
        ]);
    }

    public function deleteTour(Request $request)
    {
        $request->validate([
            'tourId' => 'required|integer|min:1|exists:tbl_tours,tourId',
        ]);

        $tourId = (int) $request->input('tourId');

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

    /**
     * Sửa Bước 1 của tour NHÁP trong wizard. Trước đây sửa Bước 1 sau khi đã tạo thì không lưu.
     * Chỉ áp dụng cho tour chưa đăng (availability = 0); tour đã đăng sửa bằng nút Sửa trong danh sách.
     */
    public function updateBasicTour(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'tourId' => 'required|integer|min:1',
        ], [
            'tourId.required' => 'Thiếu mã tour.',
            'tourId.integer'  => 'Mã tour không hợp lệ.',
            'tourId.min'      => 'Mã tour không hợp lệ.',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => $validator->errors()->first(),
            ]);
        }

        $tourId = (int) $request->input('tourId');

        [$fields, $error] = $this->parseBasicTourInput($request);
        if ($error) {
            return $error;
        }

        try {
            $updated = DB::transaction(function () use ($tourId, $fields) {
                $tour = DB::table('tbl_tours')
                    ->where('tourId', $tourId)
                    ->lockForUpdate()
                    ->first();

                if (!$tour) {
                    return [
                        'success' => false,
                        'status' => 404,
                        'message' => 'Không tìm thấy tour.',
                    ];
                }

                if ((int) $tour->availability === 1) {
                    return [
                        'success' => false,
                        'status' => 409,
                        'message' => 'Tour đã đăng. Hãy dùng nút Sửa trong danh sách tour.',
                    ];
                }

                $this->tours->updateTour($tourId, $fields);

                return [
                    'success' => true,
                    'status' => 200,
                ];
            });

            if (!$updated['success']) {
                return response()->json([
                    'success' => false,
                    'message' => $updated['message'],
                ], $updated['status']);
            }

            return response()->json([
                'success' => true,
                'message' => 'Đã lưu thay đổi Bước 1.',
                'tourId'  => $tourId,
            ]);
        } catch (\Throwable $e) {
            report($e);

            return response()->json([
                'success' => false,
                'message' => 'Không thể lưu thay đổi tour. Vui lòng thử lại.',
            ], 500);
        }
    }
}
