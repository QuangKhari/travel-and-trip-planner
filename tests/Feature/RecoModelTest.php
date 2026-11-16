<?php

namespace Tests\Feature;

use App\Console\Commands\RecoInterleaveReport;
use App\Services\Planner\Reco\RecoEval;
use App\Services\Planner\Reco\RecoModel;
use App\Services\Planner\Reco\RecoRanker;
use App\Services\Planner\Reco\RecoSimulator;
use App\Support\PasswordHasher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** Đợt E (L3–L9): mô hình cá nhân hóa học đúng (trên người dùng giả lập), xếp lại danh sách thật, minh bạch, so sánh xen kẽ. */
class RecoModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_model_learns_from_simulated_users_and_recovers_their_preferences(): void
    {
        $s = RecoSimulator::generate(50, 10);
        $r = RecoEval::run($s['lab'], $s['features']);
        $m = $r['methods'];
        // học được: hơn hẳn "độ nổi bật", và có thích nghi theo người (≥ mô hình chung)
        $this->assertGreaterThan($m['pop']['auc'] + 0.05, $m['model']['auc']);
        $this->assertGreaterThanOrEqual($m['global']['auc'] - 0.005, $m['model']['auc']);

        // sau khi học, mỗi người giả được xếp nơi thuộc nhóm họ THẬT SỰ thích lên trên nhóm họ không thích
        $model = $r['model'];
        $tEnd = strtotime(end($s['lab'])['createdAt']) + 60;
        $keys = array_values(array_unique(array_map(fn($x) => $x['refType'] . ':' . $x['refId'], $s['lab'])));
        $ok = $n = 0;
        foreach (range(1, 12) as $u) {
            $like = array_keys(array_filter($s['truth'][$u], fn($t) => $t >= 0.8));
            $hate = array_keys(array_filter($s['truth'][$u], fn($t) => $t <= -0.8));
            if (!$like || !$hate) {
                continue;
            }
            $avg = function (array $groups) use ($model, $s, $keys, $u, $tEnd) {
                $sc = [];
                foreach ($keys as $k) {
                    if (in_array($s['features']->groupOf($k), $groups, true)) {
                        $sc[] = $model->score($s['features']->vector($u, $k, $tEnd));
                    }
                }

                return $sc ? array_sum($sc) / count($sc) : 0.0;
            };
            $n++;
            $ok += $avg($like) > $avg($hate) ? 1 : 0;
        }
        $this->assertGreaterThan(0, $n);
        $this->assertGreaterThanOrEqual(0.8, $ok / $n);
    }

    public function test_metrics_are_correct_on_small_examples(): void
    {
        $this->assertSame(1.0, RecoEval::auc([0.9, 0.1, 0.2], [1, 0, 0]));
        $this->assertSame(0.5, RecoEval::auc([0.5, 0.5], [1, 0]));
        $this->assertEqualsWithDelta(1.0, RecoEval::ndcg([0, 1, 2], [0.9, 0.5, 0.1], [1, 0, 0], 5), 1e-9);
        $this->assertEqualsWithDelta(1 / log(3, 2), RecoEval::ndcg([0, 1, 2], [0.1, 0.9, 0.5], [0, 0, 1], 5), 1e-9);
        $this->assertEqualsWithDelta(0.0414, RecoInterleaveReport::signTest(15, 20), 0.001);   // 15/20, hai phía: 2 × 0,0207
    }

    public function test_ranker_reorders_only_given_candidates_with_reasons_and_explore_slot(): void
    {
        Storage::fake('local');
        Cache::flush();
        $s = RecoSimulator::generate(30, 6);
        $rows = array_map(fn($x) => ['x' => $s['features']->vector($x['userId'], $x['refType'] . ':' . $x['refId'], strtotime($x['createdAt']), ['position' => $x['position']]), 'y' => $x['label']], $s['lab']);
        RecoModel::train($rows, $s['features']->names())->save();
        config(['reco.mode' => 'model', 'reco.explore_slot' => 2]);

        $u = DB::table('tbl_users')->insertGetId(['username' => 'rk', 'email' => 'rk@example.com', 'password' => PasswordHasher::make('12345678'), 'fullName' => 'R', 'isActive' => 'y']);
        $d = DB::table('tbl_destinations')->insertGetId(['slug' => 'x', 'name' => 'X', 'province' => 'x', 'domain' => 'b', 'description' => 'x', 'latitude' => 11.9, 'longitude' => 108.4, 'isActive' => 1]);
        $kinds = ['museum', 'viewpoint', 'museum', 'beach', 'museum', 'restaurant'];
        $in = [];
        foreach ($kinds as $i => $k) {
            $id = DB::table('tbl_external_places')->insertGetId(['destinationId' => $d, 'source' => 'import', 'externalId' => "k$i", 'name' => "Nơi $i", 'category' => $k === 'restaurant' ? 'food' : 'sight',
                'kind' => $k, 'latitude' => 11.9, 'longitude' => 108.4, 'rank' => 10 - $i, 'reviewStatus' => 'published', 'syncedAt' => now()]);
            $in[] = ['type' => 'place', 'refId' => $id, 'name' => "Nơi $i"];
        }
        $r = RecoRanker::rank($u, 'browse', $in);
        $this->assertSame('model', $r['policy']);
        $this->assertEqualsCanonicalizing(array_column($in, 'refId'), array_column($r['results'], 'refId'));   // không thêm / bớt nơi
        $this->assertArrayHasKey('why', $r['results'][0]);
        $this->assertTrue(collect($r['results'])->contains(fn($x) => !empty($x['explore'])) || count(array_unique($kinds)) < 3);

        // tìm có chữ: giữ thứ tự khớp chữ
        $this->assertSame('rules', RecoRanker::rank($u, 'search', $in)['policy']);
        // xen kẽ: mỗi mục có đội A/B, đủ mục
        config(['reco.mode' => 'interleave']);
        $il = RecoRanker::rank($u, 'browse', $in)['results'];
        $this->assertCount(count($in), $il);
        $this->assertEqualsCanonicalizing(['A', 'B'], array_values(array_unique(array_column($il, 'team'))));
        // tắt: như cũ
        config(['reco.mode' => 'rules']);
        $this->assertSame($in, RecoRanker::rank($u, 'browse', $in)['results']);
    }
}
