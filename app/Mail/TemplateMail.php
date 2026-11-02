<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Attachment;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Email gửi qua hàng đợi (B4): controller không chờ SMTP, lỗi gửi được worker thử lại.
 *   Mail::to($email)->queue(new TemplateMail('admin.emails.invoice', $data, 'Tiêu đề', $pdfBase64, 'hoa-don.pdf'));
 * Chạy worker: php artisan queue:work (QUEUE_CONNECTION=database hoặc redis). Khi test, queue = sync.
 * Tệp đính kèm truyền dạng base64 để job tuần tự hóa được.
 */
class TemplateMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public int $tries = 3;
    public array $backoff = [30, 120];

    public function __construct(
        public string $template,
        public array $payload,
        public string $subjectLine,
        public ?string $attachmentBase64 = null,
        public ?string $attachmentName = null,
        public string $attachmentMime = 'application/pdf',
    ) {}

    public function envelope(): Envelope
    {
        // bỏ xuống dòng: tiêu đề có thể chứa tên do khách nhập
        return new Envelope(subject: str_replace(["\r", "\n"], ' ', $this->subjectLine));
    }

    public function content(): Content
    {
        return new Content(view: $this->template, with: $this->payload);
    }

    public function attachments(): array
    {
        if (!$this->attachmentBase64 || !$this->attachmentName) {
            return [];
        }
        $bytes = base64_decode($this->attachmentBase64, true);

        return $bytes === false ? [] : [Attachment::fromData(fn() => $bytes, $this->attachmentName)->withMime($this->attachmentMime)];
    }
}
