<?php

namespace Tests\Feature;

use App\Services\Planner\TripCommunity;
use App\Services\Planner\TripPlanner;
use App\Services\Planner\TripSharing;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Lên kế hoạch nhóm: liên kết mời, quyền riêng tư khi chia sẻ, lưu bản sao, chi tiêu nhóm, checklist "hôm nay" (đã ghé / bỏ qua),
 * đặt chỗ ngoài Travela (còn thiếu gì, cần đặt -> đã đặt -> đã hủy), nhắc đặt chỗ, "Làm thành chuyến của bạn".
 */
class GroupTripTest extends TestCase
{
    use RefreshDatabase;

    private int $dest;
    private int $owner;
    private int $friend;
    private int $other;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dest = DB::table('tbl_destinations')->insertGetId(['slug' => 'da-lat', 'name' => 'Đà Lạt', 'province' => 'Lâm Đồng', 'domain' => 'b',
            'description' => 'mô tả', 'latitude' => 11.94, 'longitude' => 108.44, 'isActive' => 1]);
        $this->owner = $this->user('chu', 'Chủ Chuyến');
        $this->friend = $this->user('ban', 'Bạn Đi Cùng');
        $this->other = $this->user('khac', 'Người Khác');
    }

    private function user(string $u, string $name): int
    {
        return DB::table('tbl_users')->insertGetId(['username' => $u, 'email' => $u . '@example.com', 'password' => 'x', 'fullName' => $name, 'isActive' => 'y']);
    }

    private function as(int $uid): static
    {
        return $this->withSession(['userId' => $uid, 'username' => 'u' . $uid]);
    }

    private function trip(int $days = 3, ?string $start = null): int
    {
        return app(TripPlanner::class)->create($this->owner, ['title' => 'Đà Lạt nhóm', 'days' => $days, 'groupSize' => 3,
            'startDate' => $start ?? now()->addDays(20)->toDateString(), 'destinationId' => $this->dest]);
    }

    private function save(int $id, array $items, int $days = 3): void
    {
        $t = DB::table('tbl_trips')->where('tripId', $id)->first();
        $this->as($this->owner)->postJson("/len-ke-hoach/$id/luu", ['title' => $t->title, 'days' => $days, 'groupSize' => 3,
            'startDate' => $t->startDate, 'items' => $items])->assertOk();
    }

    private function custom(string $label, int $day, ?string $note = null, array $extra = []): array
    {
        return ['type' => 'note', 'label' => $label, 'note' => $note, 'lat' => 11.94, 'lng' => 108.44, 'day' => $day] + $extra;
    }

    // ------------------------------------------------------------------ liên kết mời

    public function test_join_link_lets_people_join_with_the_chosen_role(): void
    {
        $id = $this->trip();
        $url = $this->as($this->owner)->postJson("/len-ke-hoach/$id/lien-ket-moi", ['on' => true, 'role' => 'view'])->assertOk()->json('url');
        $token = basename($url);
        $this->assertSame(32, strlen($token));

        // người khác không bật / đổi được
        $this->as($this->friend)->postJson("/len-ke-hoach/$id/lien-ket-moi", ['on' => true])->assertForbidden();

        $this->as($this->friend)->get("/chuyen-di/tham-gia/$token")->assertOk()->assertSee('Đà Lạt nhóm');
        $this->as($this->friend)->post("/chuyen-di/tham-gia/$token")->assertRedirect(route('planner.edit', ['id' => $id]));
        $this->assertSame('view', DB::table('tbl_trip_members')->where(['tripId' => $id, 'userId' => $this->friend])->value('role'));
        // vào lần nữa không nhân bản, chủ chuyến tự vào cũng không thành thành viên
        $this->as($this->friend)->post("/chuyen-di/tham-gia/$token");
        $this->as($this->owner)->post("/chuyen-di/tham-gia/$token");
        $this->assertSame(1, DB::table('tbl_trip_members')->where('tripId', $id)->count());

        // tắt liên kết: liên kết cũ hết hiệu lực
        $this->as($this->owner)->postJson("/len-ke-hoach/$id/lien-ket-moi", ['on' => false])->assertOk()->assertJsonPath('url', null);
        $this->as($this->other)->post("/chuyen-di/tham-gia/$token")->assertRedirect(route('planner'));
        $this->assertFalse(DB::table('tbl_trip_members')->where(['tripId' => $id, 'userId' => $this->other])->exists());
    }

    public function test_removed_member_cannot_rejoin_with_old_link_and_member_limit_holds(): void
    {
        $id = $this->trip();
        $token = basename($this->as($this->owner)->postJson("/len-ke-hoach/$id/lien-ket-moi", ['on' => true])->json('url'));
        $this->as($this->friend)->post("/chuyen-di/tham-gia/$token");
        $this->assertTrue(app(TripSharing::class)->remove($this->owner, $id, $this->friend));

        $this->assertNotSame($token, DB::table('tbl_trips')->where('tripId', $id)->value('joinToken'), 'mời ra thì đổi liên kết');
        $this->as($this->friend)->post("/chuyen-di/tham-gia/$token");
        $this->assertFalse(DB::table('tbl_trip_members')->where(['tripId' => $id, 'userId' => $this->friend])->exists());

        // tự rời nhóm thì không cần đổi liên kết
        $token = DB::table('tbl_trips')->where('tripId', $id)->value('joinToken');
        $this->as($this->other)->post("/chuyen-di/tham-gia/$token");
        app(TripSharing::class)->remove($this->other, $id, $this->other);
        $this->assertSame($token, DB::table('tbl_trips')->where('tripId', $id)->value('joinToken'));

        for ($i = 0; $i < TripSharing::MAX_MEMBERS; $i++) {
            DB::table('tbl_trip_members')->insert(['tripId' => $id, 'userId' => $this->user('m' . $i, 'M' . $i), 'role' => 'edit', 'createdAt' => now()]);
        }
        $r = app(TripSharing::class)->join($this->friend, $token);
        $this->assertFalse($r['ok']);
    }

    // ------------------------------------------------------------------ chia sẻ chỉ xem + quyền riêng tư

    public function test_shared_view_respects_privacy_and_never_shows_booking_codes(): void
    {
        $id = $this->trip();
        $this->save($id, [$this->custom('Quán bún bí mật', 1, 'Ghi chú riêng tư XYZ', ['cost' => 250000])]);
        $this->as($this->owner)->postJson("/len-ke-hoach/$id/xac-nhan", ['kind' => 'hotel', 'title' => 'Hotel Sen', 'code' => 'BK-778899',
            'startAt' => now()->addDays(20)->setTime(14, 0)->format('Y-m-d\TH:i'), 'price' => 900000])->assertOk();
        $url = $this->as($this->owner)->postJson("/len-ke-hoach/$id/chia-se", ['on' => true])->assertOk()->json('url');

        // mặc định: ghi chú hiện, đặt chỗ và chi phí ẩn
        $this->get($url)->assertOk()->assertSee('Quán bún bí mật')->assertSee('Ghi chú riêng tư XYZ')->assertDontSee('Đã đặt chỗ')->assertDontSee('BK-778899');

        $this->as($this->friend)->postJson("/len-ke-hoach/$id/rieng-tu", ['shareNotes' => false])->assertForbidden();
        $this->as($this->owner)->postJson("/len-ke-hoach/$id/rieng-tu", ['shareNotes' => false, 'shareBookings' => true])->assertOk();
        $html = $this->get($url)->assertOk()->assertSee('Quán bún bí mật', false)->assertDontSee('Ghi chú riêng tư XYZ')->getContent();
        $this->assertStringContainsString('Đã đặt chỗ', $html);
        $this->assertStringNotContainsString('BK-778899', $html, 'mã xác nhận không bao giờ ra bản chia sẻ');
        // mã cũng không nằm trong ghi chú của mục lịch
        $this->assertFalse(DB::table('tbl_trip_items')->where('tripId', $id)->where('note', 'like', '%BK-778899%')->exists());

        // tắt chia sẻ: liên kết hết hiệu lực
        $this->as($this->owner)->postJson("/len-ke-hoach/$id/chia-se", ['on' => false])->assertOk();
        $this->get($url)->assertNotFound();
    }

    public function test_save_copy_of_shared_trip_is_clean_and_respects_hidden_notes(): void
    {
        $id = $this->trip();
        $this->save($id, [$this->custom('Đồi chè', 1, 'Mật khẩu wifi homestay', ['visitedAt' => now()->toIso8601String()])]);
        $token = basename($this->as($this->owner)->postJson("/len-ke-hoach/$id/chia-se", ['on' => true])->json('url'));
        $this->as($this->owner)->postJson("/len-ke-hoach/$id/lien-ket-moi", ['on' => true]);
        $this->as($this->owner)->postJson("/len-ke-hoach/$id/rieng-tu", ['shareNotes' => false]);

        $res = $this->as($this->other)->post("/chuyen-di/$token/luu-ban-sao");
        $copy = DB::table('tbl_trips')->where('userId', $this->other)->where('kind', 'trip')->orderByDesc('tripId')->first();
        $res->assertRedirect(route('planner.edit', ['id' => $copy->tripId]));
        $this->assertNull($copy->shareToken);
        $this->assertNull($copy->joinToken);
        $this->assertSame(0, (int) $copy->isPublic);
        $item = DB::table('tbl_trip_items')->where('tripId', $copy->tripId)->first();
        $this->assertSame('Đồi chè', $item->label);
        $this->assertNull($item->note, 'ghi chú đang ẩn thì bản sao cũng không có');
        $this->assertNull($item->visitedAt, 'dấu "đã ghé" là của người đi, không chép');
        // chuyến gốc giữ nguyên
        $this->assertSame('Mật khẩu wifi homestay', DB::table('tbl_trip_items')->where('tripId', $id)->value('note'));
    }

    public function test_duplicate_and_community_clone_reset_tokens_and_progress(): void
    {
        $id = $this->trip();
        $this->save($id, [$this->custom('A', 1, null, ['visitedAt' => now()->toIso8601String()]), $this->custom('B', 1, null, ['skippedAt' => now()->toIso8601String()])]);
        $this->as($this->owner)->postJson("/len-ke-hoach/$id/chia-se", ['on' => true]);
        $this->as($this->owner)->postJson("/len-ke-hoach/$id/lien-ket-moi", ['on' => true]);
        $this->assertNotNull(DB::table('tbl_trip_items')->where(['tripId' => $id, 'label' => 'B'])->value('skippedAt'), 'bỏ qua được lưu');

        DB::table('tbl_trips')->where('tripId', $id)->update(['isPublic' => 1, 'publishedAt' => now(), 'likeCount' => 5]);
        $clone = app(TripCommunity::class)->cloneTrip($this->other, $id, ['groupSize' => 2]);
        $row = DB::table('tbl_trips')->where('tripId', $clone)->first();
        $this->assertNull($row->joinToken);
        $this->assertNull($row->shareToken);
        $this->assertSame(0, (int) $row->isPublic);
        $this->assertSame(0, (int) $row->likeCount);
        $this->assertSame(0, DB::table('tbl_trip_items')->where('tripId', $clone)->where(fn($q) => $q->whereNotNull('visitedAt')->orWhereNotNull('skippedAt'))->count());
    }

    // ------------------------------------------------------------------ chi tiêu nhóm

    public function test_group_expenses_split_balance_and_permissions(): void
    {
        $id = $this->trip();
        DB::table('tbl_trip_members')->insert([
            ['tripId' => $id, 'userId' => $this->friend, 'role' => 'edit', 'createdAt' => now()],
            ['tripId' => $id, 'userId' => $this->other, 'role' => 'view', 'createdAt' => now()],
        ]);
        $base = "/len-ke-hoach/$id/chi-tieu";
        $g = $this->as($this->owner)->postJson("/len-ke-hoach/$id/nguoi-di-cung", ['name' => 'Bé Na'])->assertOk()->json();
        $guest = collect($g['people'] ?? [])->firstWhere('name', 'Bé Na')['who'] ?? 'g:' . DB::table('tbl_trip_guests')->where('tripId', $id)->value('guestId');
        $this->as($this->owner)->postJson("/len-ke-hoach/$id/nguoi-di-cung", ['name' => ' bé  na '])->assertStatus(422);

        $o = 'u:' . $this->owner;
        $f = 'u:' . $this->friend;
        // chủ trả 300.000 chia đều 3 người (chủ, bạn, bé Na)
        $j = $this->as($this->owner)->postJson($base, ['title' => 'Lẩu gà', 'amount' => 300000, 'category' => 'food', 'paidBy' => $o, 'splitMode' => 'equal', 'who' => [$o, $f, $guest]])
            ->assertOk()->json();
        $this->assertSame(200000, $j['balance'][$o]);
        $this->assertSame(-100000, $j['balance'][$f]);
        // bạn trả 90.000 chia tay: bạn 30k, chủ 60k
        $j = $this->as($this->friend)->postJson($base, ['title' => 'Cà phê', 'amount' => 90000, 'paidBy' => $f, 'splitMode' => 'custom', 'shares' => [$f => 30000, $o => 60000]])
            ->assertOk()->json();
        $this->assertSame(390000, $j['total']);
        $this->assertSame(0, array_sum($j['balance']), 'sổ luôn cân');
        $this->assertSame(140000, $j['balance'][$o]);
        $this->assertSame(-40000, $j['balance'][$f]);
        $this->assertSame(-100000, $j['balance'][$guest]);
        $this->assertCount(2, $j['settle']);

        // chia tay sai tổng, người ngoài nhóm, người chỉ xem, người lạ
        $this->as($this->owner)->postJson($base, ['title' => 'X', 'amount' => 1000, 'paidBy' => $o, 'splitMode' => 'custom', 'shares' => [$o => 500]])->assertStatus(422);
        $this->as($this->owner)->postJson($base, ['title' => 'X', 'amount' => 1000, 'paidBy' => 'u:999999', 'splitMode' => 'equal', 'who' => [$o]])->assertStatus(422);
        $this->as($this->other)->postJson($base, ['title' => 'X', 'amount' => 1000, 'paidBy' => $o, 'splitMode' => 'equal', 'who' => [$o]])->assertForbidden();
        $this->as($this->other)->getJson($base)->assertOk()->assertJsonPath('canEdit', false);
        $stranger = $this->user('la', 'Lạ');
        $this->as($stranger)->getJson($base)->assertNotFound();

        // bé Na đang có trong khoản chi: không gỡ được
        $this->as($this->owner)->postJson("/len-ke-hoach/$id/nguoi-di-cung/" . substr($guest, 2) . '/xoa')->assertStatus(422);

        // chỉ người ghi khoản hoặc chủ chuyến được xóa
        $lau = DB::table('tbl_trip_expenses')->where(['tripId' => $id, 'title' => 'Lẩu gà'])->value('expenseId');
        $this->as($this->friend)->postJson("$base/$lau/xoa")->assertForbidden();
        $cafe = DB::table('tbl_trip_expenses')->where(['tripId' => $id, 'title' => 'Cà phê'])->value('expenseId');
        $this->as($this->owner)->postJson("$base/$cafe/xoa")->assertOk();
        $this->assertFalse(DB::table('tbl_trip_expense_shares')->where('expenseId', $cafe)->exists());
    }

    public function test_member_leaving_keeps_their_expenses_as_a_named_guest(): void
    {
        $id = $this->trip();
        DB::table('tbl_trip_members')->insert(['tripId' => $id, 'userId' => $this->friend, 'role' => 'edit', 'createdAt' => now()]);
        $o = 'u:' . $this->owner;
        $f = 'u:' . $this->friend;
        $this->as($this->friend)->postJson("/len-ke-hoach/$id/chi-tieu", ['title' => 'Xăng xe', 'amount' => 200000, 'paidBy' => $f, 'splitMode' => 'equal', 'who' => [$o, $f]])->assertOk();

        $this->assertTrue(app(TripSharing::class)->remove($this->friend, $id, $this->friend));
        $j = $this->as($this->owner)->getJson("/len-ke-hoach/$id/chi-tieu")->assertOk()->json();
        $guest = DB::table('tbl_trip_guests')->where('tripId', $id)->first();
        $this->assertSame('Bạn Đi Cùng', $guest->name);
        $this->assertSame(100000, $j['balance']['g:' . $guest->guestId]);
        $this->assertSame(-100000, $j['balance'][$o]);
        $this->assertArrayNotHasKey($f, $j['balance']);
    }

    // ------------------------------------------------------------------ đặt chỗ ngoài Travela

    public function test_booking_plan_finds_gaps_and_follows_planned_booked_cancelled(): void
    {
        $start = now()->addDays(20)->startOfDay();
        $id = $this->trip(3, $start->toDateString());
        $p = $this->as($this->owner)->getJson("/len-ke-hoach/$id/dat-cho")->assertOk()->json();
        $keys = array_column($p['needs'], 'key');
        $this->assertSame(['stay', 'go', 'back'], $keys);
        $this->assertSame(2, $p['needs'][0]['nights']);
        $this->assertStringContainsString('checkin=' . $start->toDateString(), $p['needs'][0]['links'][0]['url']);

        // ghi vào "cần đặt" rồi đánh dấu đã đặt: mục nhận phòng vào lịch lúc 14:00
        $j = $this->as($this->owner)->postJson("/len-ke-hoach/$id/dat-cho", ['kind' => 'hotel', 'title' => 'Homestay Mây', 'startAt' => $start->format('Y-m-d') . 'T12:00',
            'endAt' => $start->copy()->addDays(2)->format('Y-m-d') . 'T12:00', 'needBy' => now()->toDateString(), 'bookUrl' => 'https://www.booking.com/x'])->assertOk()->json();
        $this->assertNotNull($j['needs'][0]['plannedId']);
        $xid = $j['planned'][0]['id'];
        $this->as($this->owner)->postJson("/len-ke-hoach/$id/dat-cho", ['kind' => 'hotel', 'title' => 'X', 'bookUrl' => 'javascript:alert(1)'])->assertStatus(422);
        $this->as($this->friend)->postJson("/len-ke-hoach/$id/dat-cho", ['kind' => 'hotel', 'title' => 'X'])->assertStatus(404);

        $j = $this->as($this->owner)->postJson("/len-ke-hoach/$id/dat-cho/$xid", ['status' => 'booked', 'code' => 'HM-1', 'price' => 1200000])->assertOk()->json();
        $this->assertNotContains('stay', array_column($j['needs'], 'key'), 'hai đêm đã có chỗ ở');
        $this->assertTrue(DB::table('tbl_trip_items')->where(['tripId' => $id, 'label' => 'Nhận phòng Homestay Mây', 'dayNo' => 1, 'startMin' => 840])->exists());

        // không cần vé đi (tự lái xe)
        $j = $this->as($this->owner)->postJson("/len-ke-hoach/$id/dat-cho/bo-qua", ['key' => 'go', 'skip' => true])->assertOk()->json();
        $this->assertSame(['back'], array_column($j['needs'], 'key'));

        // đổi ngày đi: đặt chỗ cũ bị đánh dấu nằm ngoài chuyến
        DB::table('tbl_trips')->where('tripId', $id)->update(['startDate' => $start->copy()->addDays(10)->toDateString()]);
        $j = $this->as($this->owner)->getJson("/len-ke-hoach/$id/dat-cho")->json();
        $this->assertTrue($j['booked'][0]['outside']);
        $this->assertContains('stay', array_column($j['needs'], 'key'));
        DB::table('tbl_trips')->where('tripId', $id)->update(['startDate' => $start->toDateString()]);

        // hủy: gỡ khỏi lịch, giữ bản ghi
        $j = $this->as($this->owner)->postJson("/len-ke-hoach/$id/dat-cho/$xid", ['status' => 'cancelled'])->assertOk()->json();
        $this->assertSame(1, $j['cancelled']);
        $this->assertFalse(DB::table('tbl_trip_items')->where(['tripId' => $id, 'label' => 'Nhận phòng Homestay Mây'])->exists());
        $this->assertTrue(DB::table('tbl_trip_external')->where('externalId', $xid)->exists());
    }

    public function test_editor_only_sees_booked_items_and_removing_planned_leaves_calendar_alone(): void
    {
        $id = $this->trip();
        $this->save($id, [$this->custom('Nhận phòng Hotel Sen', 1)]);   // trùng nhãn với một mục người dùng tự thêm
        $this->as($this->owner)->postJson("/len-ke-hoach/$id/dat-cho", ['kind' => 'hotel', 'title' => 'Hotel Sen'])->assertOk();
        $xid = DB::table('tbl_trip_external')->where('tripId', $id)->value('externalId');
        $this->as($this->owner)->postJson("/len-ke-hoach/$id/ngoai/$xid/xoa")->assertOk();
        $this->assertFalse(DB::table('tbl_trip_external')->where('externalId', $xid)->exists());
        $this->assertTrue(DB::table('tbl_trip_items')->where(['tripId' => $id, 'label' => 'Nhận phòng Hotel Sen'])->exists(), 'mục "cần đặt" chưa vào lịch nên xóa không đụng lịch');
    }

    public function test_booking_reminder_emails_owner_once_a_day(): void
    {
        Mail::fake();
        $id = $this->trip(3, now()->addDays(2)->toDateString());
        $this->as($this->owner)->postJson("/len-ke-hoach/$id/dat-cho", ['kind' => 'flight', 'title' => 'Bay đi Liên Khương', 'needBy' => now()->toDateString()])->assertOk();

        $this->artisan('trips:booking-reminders', ['--dry' => true])->expectsOutputToContain('1 tới hạn')->assertSuccessful();
        Mail::assertNothingQueued();
        $this->artisan('trips:booking-reminders')->assertSuccessful();
        $this->artisan('trips:booking-reminders')->assertSuccessful();
        Mail::assertQueuedCount(1);

        // chuyến xa, không có việc tới hạn: không nhắc
        DB::table('tbl_trips')->where('tripId', $id)->update(['startDate' => now()->addDays(25)->toDateString()]);
        DB::table('tbl_trip_external')->where('tripId', $id)->update(['needBy' => now()->addDays(10)->toDateString()]);
        $this->artisan('trips:booking-reminders', ['--dry' => true])->expectsOutputToContain('Đã xếp 0')->assertSuccessful();
    }

    // ------------------------------------------------------------------ làm thành chuyến của bạn

    public function test_remix_community_trip_from_one_sentence(): void
    {
        $id = $this->trip(3);
        $this->save($id, [$this->custom('Hồ Xuân Hương', 1), $this->custom('Đồi chè', 2), $this->custom('Thác Datanla', 3)]);
        DB::table('tbl_trips')->where('tripId', $id)->update(['isPublic' => 1, 'publishedAt' => now(), 'budget' => 3000000]);

        $this->post("/cong-dong/$id/lam-cua-toi", ['text' => '2 người'])->assertRedirect();   // khách: phải đăng nhập
        $this->assertSame(0, DB::table('tbl_trips')->where('userId', $this->other)->where('kind', 'trip')->count());

        $res = $this->as($this->other)->post("/cong-dong/$id/lam-cua-toi", ['text' => '2 người, 2 ngày, khoảng 6 triệu']);
        $copy = DB::table('tbl_trips')->where('userId', $this->other)->where('kind', 'trip')->orderByDesc('tripId')->first();
        $this->assertNotNull($copy);
        $res->assertRedirect(route('planner.edit', $copy->tripId))->assertSessionHas('planner_notice');
        $this->assertSame(2, (int) $copy->groupSize);
        $this->assertSame(2, (int) $copy->days);
        $this->assertSame(6000000, (int) $copy->budget);
        // ngày 3 bị bớt: mục chuyển vào "Đã lưu", không mất
        $this->assertNull(DB::table('tbl_trip_items')->where(['tripId' => $copy->tripId, 'label' => 'Thác Datanla'])->value('dayNo'));
        $this->assertSame(3, DB::table('tbl_trip_items')->where('tripId', $copy->tripId)->count());
        // bản gốc giữ nguyên
        $this->assertSame(3, (int) DB::table('tbl_trips')->where('tripId', $id)->value('days'));

        $this->as($this->other)->post("/cong-dong/$id/lam-cua-toi", ['text' => ''])->assertSessionHasErrors('text');
    }

    public function test_resize_days_never_loses_items(): void
    {
        $id = $this->trip(3);
        $this->save($id, [$this->custom('A', 1), $this->custom('B', 2), $this->custom('C', 3)]);
        $moved = app(TripPlanner::class)->resizeDays($id, 1);
        $this->assertSame(2, $moved);
        $this->assertSame(1, (int) DB::table('tbl_trips')->where('tripId', $id)->value('days'));
        $this->assertSame(3, DB::table('tbl_trip_items')->where('tripId', $id)->count());
        $this->assertSame(2, DB::table('tbl_trip_items')->where('tripId', $id)->whereNull('dayNo')->count());
        $this->assertSame(0, app(TripPlanner::class)->resizeDays($id, 4));
        $this->assertSame(4, (int) DB::table('tbl_trips')->where('tripId', $id)->value('days'));
    }
}
