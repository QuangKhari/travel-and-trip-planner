<?php

namespace App\Http\Controllers\clients;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Validator;

class ContactController extends Controller
{
    /** Chủ đề liên hệ: mã => nhãn (nhãn hiện trong thư gửi nhân viên và ở màn hình "đã gửi"). */
    public const TOPICS = [
        'advice'  => 'Tư vấn chọn tour',
        'booking' => 'Hỗ trợ đơn đã đặt',
        'policy'  => 'Chính sách & thanh toán',
        'other'   => 'Góp ý / câu hỏi khác',
    ];

    public function index(Request $request)
    {
        $title = 'Liên hệ';

        // Đã đăng nhập: điền sẵn họ tên, SĐT, email (khách vẫn sửa được) -> gửi nhanh hơn
        $prefill = ['name' => '', 'phone_number' => '', 'email' => ''];
        if ($this->getUserId()) {
            $u = $this->user->getUser((int) $this->getUserId());
            if ($u) {
                $prefill = [
                    'name'         => (string) ($u->fullName ?? ''),
                    'phone_number' => (string) ($u->phoneNumber ?? ''),
                    'email'        => (string) ($u->email ?? ''),
                ];
            }
        }

        // /contact?topic=booking&code=TR-123 : link từ trang chi tiết đơn / chi tiết tour
        $topic = array_key_exists((string) $request->query('topic'), self::TOPICS) ? (string) $request->query('topic') : 'advice';
        $bookingCode = (string) preg_replace('/[^A-Za-z0-9\-_#]/', '', (string) $request->query('code', ''));
        if ($bookingCode !== '' && !$request->has('topic')) {
            $topic = 'booking';
        }

        $topics = self::TOPICS;

        return view('clients.contact', compact('title', 'prefill', 'topics', 'topic', 'bookingCode'));
    }

    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name'         => 'required|string|max:100',
            'phone_number' => ['required', 'regex:/^[0-9+\s.\-]{8,15}$/'],
            'email'        => ['required', 'email:filter', 'max:255'],
            'message'      => 'required|string|min:5|max:2000',
            'topic'        => 'nullable|in:' . implode(',', array_keys(self::TOPICS)),
            'booking_code' => ['nullable', 'string', 'max:40', 'regex:/^[A-Za-z0-9\-_#]+$/'],
        ], [
            'name.required'         => 'Vui lòng nhập họ tên.',
            'phone_number.required' => 'Vui lòng nhập số điện thoại.',
            'phone_number.regex'    => 'Số điện thoại không hợp lệ (8–15 ký tự: số, +, dấu cách, dấu chấm hoặc gạch ngang).',
            'email.required'        => 'Vui lòng nhập email.',
            'email.email'           => 'Email không hợp lệ.',
            'message.required'      => 'Vui lòng nhập nội dung.',
            'message.min'           => 'Nội dung hơi ngắn, bạn mô tả thêm một chút nhé (tối thiểu 5 ký tự).',
            'message.max'           => 'Nội dung tối đa 2000 ký tự.',
            'booking_code.regex'    => 'Mã đơn chỉ gồm chữ, số và dấu gạch.',
        ]);

        // Ô bẫy cho robot (người thật không thấy nên không điền): giả vờ thành công, không gửi thư
        if (filled($request->input('website'))) {
            return $request->expectsJson()
                ? response()->json(['success' => true, 'message' => 'Đã nhận tin nhắn của bạn.'])
                : redirect()->route('contact')->with('contact_sent', ['email' => '', 'phone' => '', 'topic' => '']);
        }

        if ($validator->fails()) {
            if ($request->expectsJson()) {
                return response()->json(['success' => false, 'message' => $validator->errors()->first()], 422);
            }
            // Báo lỗi ngay tại từng ô (xem contact.blade.php), giữ nguyên dữ liệu đã nhập và cuộn về biểu mẫu
            return redirect(route('contact') . '#ctForm')->withErrors($validator)->withInput();
        }

        $data = $validator->validated();
        $name = str_replace(["\r", "\n"], ' ', $data['name']);   // chống chèn header thư
        $topicKey = $data['topic'] ?? 'other';
        $topicLabel = self::TOPICS[$topicKey] ?? self::TOPICS['other'];
        $code = $data['booking_code'] ?? '';

        $body = "Chủ đề: {$topicLabel}\n"
            . ($code !== '' ? "Mã đơn: {$code}\n" : '')
            . "Họ tên: {$name}\n"
            . "Điện thoại: {$data['phone_number']}\n"
            . "Email: {$data['email']}\n\n"
            . $data['message'];

        // Gửi tới hộp thư liên hệ của cửa hàng (SITE_EMAIL); chưa cấu hình thì dùng địa chỉ gửi mặc định như trước
        $to = config('site.email') ?: config('mail.from.address');

        try {
            Mail::raw($body, function ($message) use ($data, $name, $to, $topicLabel) {
                $message->to($to)
                    ->replyTo($data['email'], $name)
                    ->subject("[{$topicLabel}] Liên hệ mới từ {$name}");
            });
        } catch (\Throwable $e) {
            Log::error('Gửi mail liên hệ thất bại.', [
                'email' => $data['email'],
                'name' => $name,
                'exception' => $e->getMessage(),
            ]);

            if ($request->expectsJson()) {
                return response()->json(['success' => false, 'message' => 'Không thể gửi liên hệ lúc này. Vui lòng thử lại sau.'], 500);
            }

            return redirect(route('contact') . '#ctForm')
                ->with('contact_failed', 'Chưa gửi được tin nhắn lúc này. Dữ liệu của bạn vẫn còn nguyên, bạn thử gửi lại sau ít phút hoặc liên hệ trực tiếp qua điện thoại/Zalo nhé.')
                ->withInput();
        }

        if ($request->expectsJson()) {
            return response()->json(['success' => true, 'message' => 'Đã nhận tin nhắn của bạn.']);
        }

        // Màn hình "đã gửi" nằm ngay trong trang (không chỉ là thông báo thoáng qua)
        return redirect(route('contact') . '#ctDone')->with('contact_sent', [
            'email' => $data['email'],
            'phone' => $data['phone_number'],
            'topic' => $topicLabel,
            'code'  => $code,
        ]);
    }
}
