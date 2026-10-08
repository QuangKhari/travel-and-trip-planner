<?php

namespace App\Http\Controllers\admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\admin\BookingModel;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\Services\UserMediaService;
use App\Services\BookingService;
use Illuminate\Support\Facades\Validator;

class BookingManagementController extends Controller
{
    private $booking;

    public function __construct()
    {
        parent::__construct();
        $this->booking = new BookingModel();
    }

    public function transferProof($id, UserMediaService $media)
    {
        $path = DB::table('tbl_booking')->where('bookingId', (int) $id)->value('transferProofImage');

        if (!$path) {
            abort(404);
        }

        $headers = [
            'Cache-Control'          => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Disposition'    => 'inline',
        ];

        if ($media->isProofPath($path)) {
            /** @var \Illuminate\Filesystem\FilesystemAdapter $disk */
            $disk = $media->proofDisk();
            if (!$disk->exists($path)) {
                abort(404);
            }

            return $disk->response($path, null, $headers);
        }

        // Dạng cũ (chưa chạy media:migrate-user-images): file còn nằm trong public/
        $legacy = public_path('clients/assets/images/transfer-proofs/' . basename($path));
        if (is_file($legacy)) {
            return response()->file($legacy, $headers);
        }

        abort(404);
    }

    public function index()
    {
        $title = 'Quản lý đặt Tour';

        $list_booking = $this->booking->getBooking();
        $list_booking = $this->updateHideBooking($list_booking);

        // dd($list_booking);

        return view('admin.booking', compact('title', 'list_booking'));
    }

    /** Từ chối nghiệp vụ: HTTP 200 + success=false để JS admin hiện đúng thông báo */
    private function refuse(string $message)
    {
        return response()->json(['success' => false, 'message' => $message]);
    }

    /** Trả lại bảng đơn mới nhất kèm thông báo thành công */
    private function bookingTableResponse(string $message)
    {
        $list_booking = $this->updateHideBooking($this->booking->getBooking());

        return response()->json([
            'success' => true,
            'message' => $message,
            'data'    => view('admin.partials.list-booking', compact('list_booking'))->render(),
        ]);
    }

    /** Đánh dấu đã thanh toán. Dùng chung cho confirmPayment và receiviedMoney. */
    private function markPaid(int $bookingId): array
    {
        return DB::transaction(function () use ($bookingId) {
            $booking  = DB::table('tbl_booking')->where('bookingId', $bookingId)->lockForUpdate()->first();
            $checkout = DB::table('tbl_checkout')->where('bookingId', $bookingId)->lockForUpdate()->first();

            if (!$booking || !$checkout) {
                return ['ok' => false, 'message' => 'Không tìm thấy đơn.'];
            }
            if ($booking->bookingStatus === 'c') {
                return ['ok' => false, 'message' => 'Đơn đã hủy, không thể xác nhận thanh toán.'];
            }
            if ($checkout->paymentStatus === 'y') {
                // Bấm lần hai: không phải lỗi (L-F-01)
                return ['ok' => true, 'message' => 'Đơn này đã được xác nhận thanh toán trước đó.'];
            }

            // Khách đã trả tiền thì đơn không được tự hủy vì quá hạn giữ chỗ
            DB::table('tbl_booking')->where('bookingId', $bookingId)->update(['holdExpiresAt' => null]);

            DB::table('tbl_checkout')->where('bookingId', $bookingId)->update([
                'paymentStatus' => 'y',
                'paymentDate'   => now(),
            ]);

            return ['ok' => true, 'message' => 'Xác nhận thanh toán thành công.'];
        });
    }

    public function confirmBooking(Request $request, BookingService $bookings)
    {
        $validator = Validator::make($request->all(), [
            'bookingId' => 'required|integer|min:1',
        ]);

        if ($validator->fails()) {
            return $this->refuse($validator->errors()->first());
        }

        $bookingId = (int) $request->input('bookingId');

        $result = DB::transaction(function () use ($bookingId, $bookings) {
            $booking = DB::table('tbl_booking')
                ->where('bookingId', $bookingId)
                ->lockForUpdate()
                ->first();

            $checkout = DB::table('tbl_checkout')
                ->where('bookingId', $bookingId)
                ->lockForUpdate()
                ->first();

            if (!$booking) {
                return ['ok' => false, 'message' => 'Không tìm thấy đơn.'];
            }

            if (!$checkout) {
                return ['ok' => false, 'message' => 'Đơn chưa có thông tin thanh toán.'];
            }
            if ($booking->bookingStatus === 'y') {
                return ['ok' => true, 'message' => 'Đơn đã được xác nhận trước đó.'];
            }
            if (
                $booking->bookingStatus === 'n'
                && $booking->holdExpiresAt !== null
                && \Carbon\Carbon::parse($booking->holdExpiresAt)->isPast()
            ) {
                if ($checkout->paymentStatus === 'y') {
                    DB::table('tbl_booking')
                        ->where('bookingId', $bookingId)
                        ->update(['holdExpiresAt' => null]);
                } else {
                    $bookings->cancelLocked($booking);

                    return [
                        'ok' => false,
                        'message' => 'Đơn đã hết thời gian giữ chỗ và đã được hủy.'
                    ];
                }
            }
            if ($booking->bookingStatus !== 'n') {
                return ['ok' => false, 'message' => 'Chỉ xác nhận được đơn đang chờ xử lý.'];
            }

            // Đã xác nhận thì không còn hạn giữ chỗ 
            DB::table('tbl_booking')->where('bookingId', $bookingId)->update(['bookingStatus' => 'y', 'holdExpiresAt' => null]);

            return ['ok' => true, 'message' => 'Cập nhật trạng thái thành công.'];
        });

        return $result['ok'] ? $this->bookingTableResponse($result['message']) : $this->refuse($result['message']);
    }

    public function finishBooking(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'bookingId' => 'required|integer|min:1',
        ]);

        if ($validator->fails()) {
            return $this->refuse($validator->errors()->first());
        }

        $bookingId = (int) $request->input('bookingId');
        $today = now()->toDateString();

        $result = DB::transaction(function () use ($bookingId, $today) {
            $row = DB::table('tbl_booking')
                ->join('tbl_tours', 'tbl_tours.tourId', '=', 'tbl_booking.tourId')
                ->join('tbl_checkout', 'tbl_checkout.bookingId', '=', 'tbl_booking.bookingId')
                ->where('tbl_booking.bookingId', $bookingId)
                ->select('tbl_booking.bookingStatus', 'tbl_tours.endDate', 'tbl_checkout.paymentStatus')
                ->lockForUpdate()
                ->first();

            if (!$row) {
                return ['ok' => false, 'message' => 'Không tìm thấy đơn.'];
            }
            if ($row->bookingStatus === 'f') {
                return ['ok' => true, 'message' => 'Đơn đã hoàn tất trước đó.'];
            }
            if ($row->bookingStatus !== 'y') {
                return ['ok' => false, 'message' => 'Chỉ hoàn tất được đơn đã xác nhận.'];
            }
            if ($row->endDate >= $today) {
                return ['ok' => false, 'message' => 'Tour chưa kết thúc nên chưa thể hoàn tất đơn.'];
            }
            if ($row->paymentStatus !== 'y') {
                return ['ok' => false, 'message' => 'Đơn chưa được xác nhận thanh toán.'];
            }

            DB::table('tbl_booking')->where('bookingId', $bookingId)->update(['bookingStatus' => 'f']);

            return ['ok' => true, 'message' => 'Cập nhật trạng thái thành công.'];
        });

        return $result['ok'] ? $this->bookingTableResponse($result['message']) : $this->refuse($result['message']);
    }

    // Trang chi tiết đơn gọi hàm này: không cần trả lại bảng
    public function receiviedMoney(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'bookingId' => 'required|integer|min:1',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => $validator->errors()->first(),
            ]);
        }

        $bookingId = (int) $request->input('bookingId');

        $result = $this->markPaid($bookingId);

        return response()->json(['success' => $result['ok'], 'message' => $result['message']]);
    }

    // Trang danh sách đơn gọi hàm này: trả lại bảng mới
    public function confirmPayment(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'bookingId' => 'required|integer|min:1',
        ]);

        if ($validator->fails()) {
            return $this->refuse($validator->errors()->first());
        }

        $bookingId = (int) $request->input('bookingId');

        $result = $this->markPaid($bookingId);

        return $result['ok']
            ? $this->bookingTableResponse($result['message'])
            : $this->refuse($result['message']);
    }

    public function showDetail($bookingId)
    {
        $title = 'Chi tiết đơn đặt';

        $invoice_booking = $bookingId ? $this->booking->getInvoiceBooking($bookingId) : null;

        // Không có id hoặc id lạ: 404 thay vì lỗi 500 (L-F-04)
        if (!$invoice_booking) {
            abort(404);
        }
        //dd($invoice_booking);
        $hide = 'hide';
        if ($invoice_booking->transactionId == null) {
            $invoice_booking->transactionId = 'Thanh toán tại công ty Travela';
        }
        if ($invoice_booking->paymentStatus === 'n') {
            $hide = '';
        }
        return view('admin.booking-detail', compact('title', 'invoice_booking', 'hide'));
    }


    public function sendPdf(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'bookingId' => 'required|integer|min:1',
            'email' => 'required|email',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => $validator->errors()->first(),
            ]);
        }

        $bookingId = (int) $request->input('bookingId');
        $email = $request->input('email');
        $title = 'Hóa đơn';
        $invoice_booking = $this->booking->getInvoiceBooking($bookingId);

        if (!$invoice_booking) {
            return response()->json(['success' => false, 'message' => 'Không tìm thấy đơn.']);
        }

        if ($invoice_booking->transactionId == null) {
            $invoice_booking->transactionId = 'Thanh toán tại công ty Travela';
        }

        try {
            Mail::send('admin.emails.invoice', compact('invoice_booking'), function ($message) use ($invoice_booking) {
                // fullName do khách nhập: bỏ xuống dòng để không chèn được header thư
                $name = str_replace(["\r", "\n"], ' ', (string) $invoice_booking->fullName);

                $message->to($invoice_booking->email)
                    ->subject('Hóa đơn đặt tour ' . $invoice_booking->bookingCode . ' - ' . $name);
            });

            return response()->json([
                'success' => true,
                'message' => 'Hóa đơn đã được gửi qua email thành công.',
            ]);
        } catch (\Throwable $e) {
            // Chi tiết lỗi chỉ ghi log, không trả cho trình duyệt
            Log::error('Gửi hóa đơn thất bại: ' . $e->getMessage(), ['bookingId' => $bookingId]);

            return response()->json([
                'success' => false,
                'message' => 'Không thể gửi email lúc này. Vui lòng thử lại sau.',
            ], 500);
        }
    }

    private function updateHideBooking($list_booking)
    {
        // Lấy ngày hiện tại
        $currentDate = date('Y-m-d');

        foreach ($list_booking as $booking) {
            // So sánh endDate của booking với ngày hiện tại
            if ($booking->endDate < $currentDate) {
                $hide = '';
            } else {
                $hide = 'hide';
            }

            // Gán giá trị $hide vào mỗi booking
            $booking->hide = $hide;
        }

        return $list_booking;
    }
}
