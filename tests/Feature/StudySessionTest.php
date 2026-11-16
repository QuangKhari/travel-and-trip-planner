<?php

namespace Tests\Feature;

use App\Services\Planner\TripPlanner;
use App\Services\Study\StudySession;
use App\Support\PasswordHasher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Đợt F: buổi thử — tạo người thử, thứ tự nhiệm vụ, tự chấm trên dữ liệu thật, SUS, báo cáo hai vòng. */
class StudySessionTest extends TestCase
{
    use RefreshDatabase;

    private int $dn;

    protected function setUp(): void
    {
        parent::setUp();
        config(['study.enabled' => true]);
        $this->dn = (int) DB::table('tbl_destinations')->insertGetId(['slug' => 'da-nang', 'name' => 'Đà Nẵng', 'province' => 'x', 'domain' => 'n', 'description' => 'x', 'latitude' => 16.05, 'longitude' => 108.2, 'isActive' => 1]);
        $owner = DB::table('tbl_users')->insertGetId(['username' => 'demo_khach', 'email' => 'dk@travela.test', 'password' => PasswordHasher::make('x'), 'fullName' => 'Demo', 'isActive' => 'y']);
        app(TripPlanner::class)->create($owner, ['title' => 'Đà Lạt 3 ngày', 'days' => 3, 'groupSize' => 2]);
    }

    private function login(string $username): object
    {
        $uid = (int) DB::table('tbl_users')->where('username', $username)->value('userId');
        $this->withSession(['userId' => $uid, 'username' => $username]);

        return DB::table('tbl_study_participants')->where('userId', $uid)->first();
    }

    public function test_setup_creates_accounts_companion_and_fix_trip_once(): void
    {
        $this->artisan('study:setup', ['--round' => 1, '--n' => 2])->assertExitCode(0);
        $this->artisan('study:setup', ['--round' => 1, '--n' => 2])->assertExitCode(0);
        $this->assertSame(2, DB::table('tbl_study_participants')->count());
        $this->assertTrue(DB::table('tbl_users')->where('username', 'ban_dong_hanh')->exists());
        $p = DB::table('tbl_study_participants')->where('code', 't1-01')->first();
        $this->assertSame('Đà Lạt 3 ngày (thử)', DB::table('tbl_trips')->where('tripId', $p->fixTripId)->value('title'));
        $this->assertSame(2, DB::table('tbl_trips')->where('title', 'Đà Lạt 3 ngày (thử)')->count());   // chạy lại không nhân thêm chuyến
    }

    public function test_bar_only_for_participants_when_enabled(): void
    {
        StudySession::setup(1, 1);
        $this->login('thu101');
        $this->get('/buoi-thu/trang-thai')->assertOk()->assertJsonPath('code', 't1-01')->assertJsonPath('consent', false);
        config(['study.enabled' => false]);
        $this->get('/buoi-thu/trang-thai')->assertNotFound();
    }

    public function test_full_session_auto_checks_and_report(): void
    {
        StudySession::setup(1, 1);
        $p = $this->login('thu101');
        $uid = (int) $p->userId;

        $this->postJson('/buoi-thu/bat-dau', ['task' => 'tour_book'])->assertStatus(422);       // chưa đồng ý
        $this->post('/buoi-thu/dong-y', ['agree' => 1, 'device' => 'mobile'])->assertRedirect();
        $this->postJson('/buoi-thu/bat-dau', ['task' => 'trip_create'])->assertStatus(422);     // chưa tới lượt
        $this->postJson('/buoi-thu/bat-dau', ['task' => 'tour_book'])->assertOk();

        // bấm Xong khi chưa đặt: chưa đạt, tính một lần vấp
        $this->postJson('/buoi-thu/xong', ['task' => 'tour_book'])->assertOk()->assertJsonPath('passed', false);
        DB::statement('SET FOREIGN_KEY_CHECKS=0');
        DB::table('tbl_booking')->insert(['tourId' => 1, 'userId' => $uid, 'fullName' => 'A', 'email' => 'a@a.vn', 'phoneNumber' => '0900000000', 'address' => 'x',
            'numChildren' => 0, 'numAdults' => 2, 'totalPrice' => 1, 'bookingCode' => 'STUDY1']);
        DB::statement('SET FOREIGN_KEY_CHECKS=1');
        $this->postJson('/buoi-thu/xong', ['task' => 'tour_book', 'clicks' => 14, 'pages' => 3])->assertOk()->assertJsonPath('passed', true)
            ->assertJsonPath('state.current.key', 'trip_create');

        // nhiệm vụ 2: chuyến Đà Nẵng 3 ngày, mỗi ngày có mục, ≥ 6 mục
        $this->postJson('/buoi-thu/bat-dau', ['task' => 'trip_create'])->assertOk();
        $planner = app(TripPlanner::class);
        $t = $planner->create($uid, ['title' => 'Đà Nẵng', 'days' => 3, 'groupSize' => 2, 'destinationId' => $this->dn]);
        $this->postJson('/buoi-thu/xong', ['task' => 'trip_create'])->assertJsonPath('passed', false);
        foreach (range(1, 6) as $i) {
            DB::table('tbl_trip_items')->insert(['tripId' => $t, 'refType' => 'note', 'refId' => 0, 'dayNo' => ($i - 1) % 3 + 1, 'orderNo' => $i, 'label' => "Mục $i"]);
        }
        $this->postJson('/buoi-thu/xong', ['task' => 'trip_create'])->assertJsonPath('passed', true);

        // nhiệm vụ 3: chuyến Đà Lạt (thử) không có lỗi nặng
        $this->postJson('/buoi-thu/bat-dau', ['task' => 'trip_fix'])->assertOk();
        $this->postJson('/buoi-thu/xong', ['task' => 'trip_fix'])->assertJsonPath('passed', true);

        // nhiệm vụ 4: mời bạn đồng hành
        $this->postJson('/buoi-thu/bat-dau', ['task' => 'trip_invite'])->assertOk();
        $this->postJson('/buoi-thu/xong', ['task' => 'trip_invite'])->assertJsonPath('passed', false);
        $r = app(\App\Services\Planner\TripSharing::class)->invite($uid, $t, 'ban.dong.hanh@travela.test', 'edit');
        $this->assertTrue($r['ok']);
        $this->postJson('/buoi-thu/xong', ['task' => 'trip_invite'])->assertJsonPath('passed', true);

        // nhiệm vụ 5: bỏ qua
        $this->postJson('/buoi-thu/bat-dau', ['task' => 'quote'])->assertOk();
        $this->postJson('/buoi-thu/xong', ['task' => 'quote', 'giveUp' => true])->assertJsonPath('state.needSus', true);

        $answers = ['q1' => 4, 'q2' => 2, 'q3' => 5, 'q4' => 1, 'q5' => 4, 'q6' => 2, 'q7' => 5, 'q8' => 1, 'q9' => 4, 'q10' => 2];
        $this->post('/buoi-thu/danh-gia', $answers + ['comment' => 'Dễ dùng'])->assertRedirect();
        $this->assertSame(85.0, (float) DB::table('tbl_study_sus')->value('score'));
        $this->get('/buoi-thu/trang-thai')->assertJsonPath('finished', true);

        $rows = DB::table('tbl_study_tasks')->orderBy('position')->get()->keyBy('task');
        $this->assertSame('success', $rows['tour_book']->outcome);
        $this->assertSame(2, (int) $rows['tour_book']->checks);
        $this->assertSame(14, (int) $rows['tour_book']->clicks);
        $this->assertSame('skip', $rows['quote']->outcome);
        $this->assertNotNull(DB::table('tbl_study_participants')->value('finishedAt'));

        $this->artisan('study:report', ['--round' => [1]])
            ->expectsOutputToContain('Vòng 1 — 1 người (1 làm xong, 1 điện thoại)')
            ->expectsOutputToContain('Đặt một tour | 1/1 (100%)')
            ->expectsOutputToContain('Hỏi giá trọn gói | 0/1 (0%)')
            ->expectsOutputToContain('SUS: 85,0')
            ->assertExitCode(0);
    }

    public function test_stats_helpers(): void
    {
        $this->assertSame([2.0, 3.0, 4.0], \App\Console\Commands\StudyReport::quartiles([1, 2, 3, 4, 5]));
        $w = \App\Console\Commands\StudyReport::welch([60, 70, 65, 75], [80, 85, 78, 90]);
        $this->assertEqualsWithDelta(15.75, $w[0], 0.01);
        $this->assertGreaterThan(0, $w[1]);                     // khác biệt rõ: khoảng không chứa 0
        $b = \App\Console\Commands\StudyReport::bootMedianDiff([100, 120, 140, 160], [60, 70, 80, 90]);
        $this->assertSame(-55.0, $b[0]);
        $this->assertLessThan(0, $b[2]);
    }
}
