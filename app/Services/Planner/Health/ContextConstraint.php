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
