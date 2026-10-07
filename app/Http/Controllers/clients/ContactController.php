<?php

namespace App\Http\Controllers\clients;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Validator;

class ContactController extends Controller
{

    public function index()
    {
        $title = 'Liên hệ';
        return view('clients.contact', compact('title'));
    }

    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name'         => 'required|string|max:100',
            'phone_number' => ['required', 'regex:/^[0-9+\s.\-]{8,15}$/'],
            'email'        => ['required', 'email:filter', 'max:255'],
            'message'      => 'required|string|max:2000',
        ], [
            'name.required'         => 'Vui lòng nhập họ tên.',
            'phone_number.required' => 'Vui lòng nhập số điện thoại.',
            'phone_number.regex'    => 'Số điện thoại không hợp lệ.',
            'email.required'        => 'Vui lòng nhập email.',
            'email.email'           => 'Email không hợp lệ.',
            'message.required'      => 'Vui lòng nhập nội dung.',
            'message.max'           => 'Nội dung tối đa 2000 ký tự.',
        ]);

        if ($validator->fails()) {
            toastr()->error($validator->errors()->first());
            return redirect()->route('contact')->withInput();
        }

        $data = $validator->validated();
        $name = str_replace(["\r", "\n"], ' ', $data['name']);   // chống chèn header thư

        $body = "Họ tên: {$name}\n"
            . "Điện thoại: {$data['phone_number']}\n"
            . "Email: {$data['email']}\n\n"
            . $data['message'];

        try {
            Mail::raw($body, function ($message) use ($data, $name) {
                $message->to(config('mail.from.address'))
                    ->replyTo($data['email'], $name)
                    ->subject('Liên hệ mới từ ' . $name);
            });
        } catch (\Throwable $e) {
            // Không cho khách thấy lỗi nội bộ; vẫn lưu nội dung vào log để không mất tin nhắn
            Log::error('Gửi mail liên hệ thất bại: ' . $e->getMessage());
            Log::info('Liên hệ chưa gửi được: ' . $body);
        }

        toastr()->success('Cảm ơn bạn, Travela đã nhận được tin nhắn và sẽ phản hồi sớm.');
        return redirect()->route('contact');
    }
}
