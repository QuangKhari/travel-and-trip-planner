<?php

namespace App\Services\Planner\Health;

/** Ngữ cảnh: mưa (tháng mưa nhiều + nhiều mục ngoài trời chưa có phương án), thời điểm nên tránh, lễ hội ngoài ngày đi. */
final class ContextConstraint implements Constraint
{
    public function group(): string
    {
        return 'context';
    }

    public function check(TripContext $ctx): array
    {
        $out = [];
        $rain = $ctx->payload['rain']['context'] ?? null;
        $plan = $ctx->payload['rain']['plan'] ?? [];
        // ngày đã có dự báo theo giờ do WeatherConstraint xét (chính xác hơn số liệu theo mùa)
        $forecast = ($ctx->payload['weather']['source'] ?? null) === 'forecast' ? (array) ($ctx->payload['weather']['days'] ?? []) : [];
        if ($rain && !empty($rain['rainy'])) {
            foreach ($ctx->days as $d) {
                if (isset($forecast[$d->day])) {
                    continue;
                }
                if ($d->outdoor >= 1 && empty($plan[$d->day])) {
                    $out[] = ['level' => $d->outdoor >= 2 ? 'warn' : 'info', 'code' => 'context.rain', 'day' => $d->day, 'action' => 'rain',
                        'text' => 'Ngày ' . $d->day . ' có ' . $d->outdoor . ' hoạt động ngoài trời; tháng ' . $rain['month'] . ' ở ' . $rain['dest'] . ' hay mưa (khoảng ' . $rain['rainyDays'] . ' ngày). Nên chuẩn bị phương án trong nhà.'];
                }
            }
        }
        foreach ($ctx->payload['advice'] ?? [] as $a) {
            if (($a['level'] ?? '') === 'avoid') {
                $out[] = ['level' => 'warn', 'code' => 'context.season', 'text' => $a['dest'] . ' tháng ' . $a['month'] . ': ' . $a['label'] . (!empty($a['note']) ? ' (' . $a['note'] . ')' : '') . '.'];
            }
        }
        // Mục tự lập rơi vào ngày đi tour đã đặt (tour do Travela vận hành cả ngày): cảnh báo theo ngày, có phương án "tour.overlap"
        foreach ((array) ($ctx->payload['tourDays'] ?? []) as $td) {
            for ($d = (int) $td['from']; $d <= (int) $td['to']; $d++) {
                $own = isset($ctx->days[$d]) ? array_filter($ctx->days[$d]->rows, fn($r) => ($r['item']['type'] ?? '') !== 'tour' && !\App\Services\Planner\TripLogistics::exempt($r['item'], \App\Services\Planner\PlannerRules::fromConfig())) : [];
                if ($own) {
                    $out[] = ['level' => 'warn', 'code' => 'tour.overlap', 'day' => $d,
                        'text' => 'Ngày ' . $d . ' là ngày đi tour “' . $td['title'] . '” (đơn ' . $td['code'] . ') nhưng lịch tự lập còn ' . count($own) . ' mục. Nên chuyển sang ngày khác hoặc để ngoài giờ tour.'];
                }
            }
        }
        $gone = (array) ($ctx->payload['unavailable'] ?? []);
        if ($gone) {
            $out[] = ['level' => 'warn', 'code' => 'content.unavailable',
                'text' => count($gone) . ' mục không còn được Travela xác thực (' . implode(', ', array_slice($gone, 0, 3)) . (count($gone) > 3 ? '…' : '')
                    . '). Mục vẫn được giữ trong chuyến; bạn nên thay bằng địa điểm khác hoặc xóa.'];
        }
        foreach ($ctx->payload['contentWarnings'] ?? [] as $w) {
            if (!str_contains($w, 'mục ngoài trời')) {   // cảnh báo mưa đã có ở trên
                $out[] = ['level' => 'info', 'code' => 'context.content', 'text' => $w];
            }
        }
        if (!array_filter($out, fn($x) => $x['level'] !== 'info')) {
            $out[] = ['level' => 'ok', 'code' => 'context.ok', 'text' => 'Thời tiết và mùa đi không có gì đáng lo.'];
        }

        return $out;
    }
}
