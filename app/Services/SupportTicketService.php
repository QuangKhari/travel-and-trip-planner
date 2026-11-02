<?php

namespace App\Services;

use App\Mail\TemplateMail;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

/**
 * Yêu cầu tư vấn (T-39 Human Travel Agent). Ticket gắn với chuyến đi; mỗi lượt trao đổi là một tin nhắn.
 * Trạng thái: open (chờ nhân viên) -> in_progress (đã nhận) -> answered (đã trả lời, chờ khách) -> closed.
 * Khách nhắn thêm thì ticket quay về open. Email gửi qua hàng đợi.
 */
class SupportTicketService
{
    public const TOPICS = ['itinerary' => 'Góp ý lịch trình', 'budget' => 'Chi phí, ngân sách', 'booking' => 'Đặt tour, dịch vụ', 'general' => 'Câu hỏi khác'];
    public const STATUS = ['open' => ['warn', 'Chờ xử lý'], 'in_progress' => ['info', 'Đang xử lý'], 'answered' => ['ok', 'Đã trả lời'], 'closed' => ['mute', 'Đã đóng']];

    public function create(int $userId, ?int $tripId, string $topic, string $subject, string $body, ?string $phone): object
    {
        return DB::transaction(function () use ($userId, $tripId, $topic, $subject, $body, $phone) {
            do {
                $code = 'TV' . now()->format('ymd') . strtoupper(Str::random(4));
            } while (DB::table('tbl_support_tickets')->where('code', $code)->exists());
            $id = DB::table('tbl_support_tickets')->insertGetId([
                'code' => $code, 'userId' => $userId, 'tripId' => $tripId,
                'topic' => array_key_exists($topic, self::TOPICS) ? $topic : 'general',
                'subject' => mb_substr($subject, 0, 160), 'contactPhone' => $phone ?: null,
                'status' => 'open', 'lastMessageAt' => now(), 'createdAt' => now(),
            ]);
            $this->addMessage($id, 'customer', $userId, $body);
            $t = DB::table('tbl_support_tickets')->where('ticketId', $id)->first();
            $this->mailUser($t, 'created', $body);

            return $t;
        });
    }

    public function addMessage(int $ticketId, string $authorType, int $authorId, string $body): void
    {
        DB::table('tbl_support_ticket_messages')->insert([
            'ticketId' => $ticketId, 'authorType' => $authorType, 'authorId' => $authorId,
            'body' => mb_substr(trim($body), 0, 4000), 'createdAt' => now(),
        ]);
    }

    public function customerReply(object $ticket, int $userId, string $body): void
    {
        $this->addMessage((int) $ticket->ticketId, 'customer', $userId, $body);
        DB::table('tbl_support_tickets')->where('ticketId', $ticket->ticketId)->update([
            'status' => 'open', 'lastMessageAt' => now(), 'customerUnread' => false, 'closedAt' => null,
        ]);
    }

    public function staffReply(object $ticket, int $adminId, string $body, bool $close): void
    {
        $this->addMessage((int) $ticket->ticketId, 'staff', $adminId, $body);
        DB::table('tbl_support_tickets')->where('ticketId', $ticket->ticketId)->update([
            'status' => $close ? 'closed' : 'answered', 'lastMessageAt' => now(), 'customerUnread' => true,
            'assignedTo' => $ticket->assignedTo ?: $adminId, 'closedAt' => $close ? now() : null,
        ]);
        $this->mailUser(DB::table('tbl_support_tickets')->where('ticketId', $ticket->ticketId)->first(), 'reply', $body);
    }

    private function mailUser(object $ticket, string $event, string $body): void
    {
        try {
            $user = DB::table('tbl_users')->where('userId', $ticket->userId)->first(['fullName', 'email']);
            if (!$user || !filter_var($user->email, FILTER_VALIDATE_EMAIL)) {
                return;
            }
            $subject = $event === 'created'
                ? 'Travela đã nhận yêu cầu tư vấn ' . $ticket->code
                : 'Travela trả lời yêu cầu tư vấn ' . $ticket->code;
            Mail::to($user->email)->queue(new TemplateMail('clients.emails.support-ticket', [
                'ticket' => $ticket, 'name' => $user->fullName, 'event' => $event, 'body' => $body,
            ], $subject));
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
