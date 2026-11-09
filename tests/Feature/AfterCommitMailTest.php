<?php

namespace Tests\Feature;

use Illuminate\Mail\Mailable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Email / job xếp hàng bên trong transaction chỉ được gửi đi SAU khi commit (config queue.connections.*.after_commit = true).
 * Transaction rollback -> khách không nhận email cho một giao dịch không tồn tại.
 */
class AfterCommitMailTest extends TestCase
{
    use RefreshDatabase;

    private function sent(): int
    {
        return count(Mail::mailer('array')->getSymfonyTransport()->messages());
    }

    private function mail(): Mailable
    {
        return new AfterCommitProbeMail();
    }

    public function test_config_dispatches_after_commit(): void
    {
        foreach (['sync', 'database', 'redis'] as $c) {
            $this->assertTrue((bool) config("queue.connections.$c.after_commit"), "$c phải after_commit");
        }
    }

    public function test_rolled_back_transaction_sends_nothing(): void
    {
        $before = $this->sent();
        try {
            DB::transaction(function () {
                Mail::to('khach@example.com')->queue($this->mail());
                throw new \RuntimeException('rollback');
            });
        } catch (\RuntimeException) {
        }
        $this->assertSame($before, $this->sent(), 'rollback thì không được gửi email');
    }

    public function test_committed_transaction_sends_after_commit(): void
    {
        $before = $this->sent();
        $during = null;
        DB::transaction(function () use (&$during, $before) {
            Mail::to('khach@example.com')->queue($this->mail());
            $during = $this->sent() - $before;
        });
        $this->assertSame(0, $during, 'chưa commit thì chưa gửi');
        $this->assertSame($before + 1, $this->sent());
    }
}

/** Email tối giản chỉ để kiểm tra thời điểm gửi. */
class AfterCommitProbeMail extends Mailable
{
    public function build(): static
    {
        return $this->from('noreply@travela.test')->subject('Thử after commit')->html('<p>ok</p>');
    }
}
