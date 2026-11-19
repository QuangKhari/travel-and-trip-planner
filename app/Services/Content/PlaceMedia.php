<?php

namespace App\Services\Content;

use App\Support\DiscoverImage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Phần "nhìn thấy được" của một mục lịch: ảnh (chỉ ảnh Travela đã lưu, có ghi nguồn), mô tả ngắn, website,
 * ô màu + biểu tượng theo nhóm khi chưa có ảnh, và nhãn mức tin cậy của dữ liệu.
 *
 * Nhãn tin cậy (không đoán, chỉ nói điều dữ liệu thật sự cho biết):
 *  - "Đã đặt"            mục gắn với một đặt chỗ đã xác nhận (vé, phòng, tour)            -> ok
 *  - "Giờ chưa kiểm tra" nơi có giờ mở cửa nhưng chưa ai xác minh trong 180 ngày, hoặc chưa có giờ -> warn
 *  - "Giá tham khảo"     có giá nhưng chưa xác minh (gói dữ liệu demo, bản đồ)              -> info
 * Mục nào không có nhãn = đã xác minh trong hạn, hoặc loại mục không có khái niệm đó (món ăn, ghi chú).
 */
final class PlaceMedia
{
    /** Nhóm hiển thị khi thiếu ảnh: nhãn + biểu tượng Font Awesome; màu nằm ở CSS (.tp-tile--{g}). */
    public const TILES = [
        'eat' => ['Ăn uống', 'fa-utensils'],
        'nature' => ['Thiên nhiên', 'fa-mountain'],
        'culture' => ['Văn hóa', 'fa-landmark'],
        'fun' => ['Vui chơi', 'fa-ticket-alt'],
        'see' => ['Tham quan', 'fa-binoculars'],
        'food' => ['Món ăn', 'fa-concierge-bell'],
        'experience' => ['Trải nghiệm', 'fa-hiking'],
        'event' => ['Sự kiện', 'fa-calendar-alt'],
        'stay' => ['Lưu trú', 'fa-bed'],
        'note' => ['Ghi chú', 'fa-map-marker-alt'],
    ];

    public static function imageReady(): bool
    {
        return Schema::hasColumn('tbl_external_places', 'coverImage');
    }

    /** @return array{g:string,label:string,icon:string} */
    public static function tile(string $type, ?string $group = null): array
    {
        $g = match ($type) {
            'place' => isset(self::TILES[$group ?? '']) ? (string) $group : 'see',
            'food', 'experience', 'event' => $type,
            'hotel', 'stay' => 'stay',
            default => 'note',
        };

        return ['g' => $g, 'label' => self::TILES[$g][0], 'icon' => self::TILES[$g][1]];
    }

    /** Nhóm hiển thị của một kind địa điểm (config planner.kind_groups), kind lạ -> see. */
    public static function groupOfKind(?string $kind): string
    {
        foreach ((array) config('planner.kind_groups') as $g => $kinds) {
            if (in_array($kind, (array) $kinds, true)) {
                return (string) $g;
            }
        }

        return 'see';
    }

    /**
     * Ảnh, mô tả, website, nguồn ảnh, trạng thái xác minh cho danh sách mục (khóa "type:refId").
     * @param list<array> $items mỗi mục có type + refId (mục bản đồ / tự thêm bỏ qua)
     * @return array<string,array{image:?string,thumb:?string,blurb:?string,website:?string,credit:?string,checked:bool,hasHours:bool,price:?float}>
     */
    public static function forItems(array $items): array
    {
        $spec = [
            'place' => ['tbl_external_places', 'placeId'],
            'food' => ['tbl_foods', 'foodId'],
            'event' => ['tbl_events', 'eventId'],
            'experience' => ['tbl_experiences', 'experienceId'],
        ];
        $ids = [];
        foreach ($items as $it) {
            $t = (string) ($it['type'] ?? '');
            if (isset($spec[$t]) && !empty($it['refId']) && empty($it['osm'])) {
                $ids[$t][(int) $it['refId']] = true;
            }
        }
        $out = [];
        foreach ($ids as $type => $list) {
            [$table, $pk] = $spec[$type];
            $cols = [$pk];
            foreach (['coverImage', 'description', 'website', 'imageCredit', 'factsCheckedAt', 'priceMin'] as $c) {
                if (Schema::hasColumn($table, $c)) {
                    $cols[] = $c;
                }
            }
            $hours = $type === 'place' || $type === 'experience'
                ? (Schema::hasTable('tbl_opening_hours') ? DB::table('tbl_opening_hours')->where('refType', $type)->whereIn('refId', array_keys($list))->pluck('refId')->flip()->all() : [])
                : [];
            foreach (DB::table($table)->whereIn($pk, array_keys($list))->get($cols) as $x) {
                $id = (int) $x->{$pk};
                $img = !empty($x->coverImage ?? null) ? DiscoverImage::url((string) $x->coverImage) : null;
                $desc = !empty($x->description ?? null) ? trim(preg_replace('/\s+/u', ' ', strip_tags((string) $x->description)) ?? '') : '';
                $site = !empty($x->website ?? null) && preg_match('#^https?://#i', (string) $x->website) ? (string) $x->website : null;
                $checked = !empty($x->factsCheckedAt ?? null) && strtotime((string) $x->factsCheckedAt) >= strtotime('-' . PlaceFacts::STALE_DAYS . ' days');
                $out[$type . ':' . $id] = ['image' => $img, 'thumb' => $img ? (DiscoverImage::thumbUrl((string) $x->coverImage) ?? $img) : null,
                    'blurb' => $desc !== '' ? Str::limit($desc, 220) : null, 'website' => $site,
                    'credit' => $img && !empty($x->imageCredit ?? null) ? (string) $x->imageCredit : null,
                    'checked' => $checked, 'hasHours' => isset($hours[$id]),
                    'price' => isset($x->priceMin) && $x->priceMin !== null ? (float) $x->priceMin : null];
            }
        }

        return $out;
    }

    /**
     * Nhãn mức tin cậy cho một mục (mục bản nháp hoặc mục chuyến).
     * @param array $it có type, priceMin|price, booked?, osm?
     * @param array $m kết quả forItems() của mục này
     * @return list<array{code:string,text:string,tone:string,tip:string}>
     */
    public static function trust(array $it, array $m): array
    {
        $type = (string) ($it['type'] ?? '');
        $tags = [];
        if (!empty($it['booked'])) {
            $tags[] = ['code' => 'booked', 'text' => 'Đã đặt', 'tone' => 'ok', 'tip' => 'Có đặt chỗ đã xác nhận cho mục này.'];
        }
        if (!empty($it['osm'])) {
            $tags[] = ['code' => 'map', 'text' => 'Từ bản đồ, chưa biên tập', 'tone' => 'warn', 'tip' => 'Lấy từ OpenStreetMap; Travela chưa kiểm tra giờ, giá.'];

            return $tags;
        }
        if (in_array($type, ['place', 'experience'], true) && empty($it['booked'])) {
            if (empty($m['hasHours'])) {
                $tags[] = ['code' => 'hours_unknown', 'text' => 'Chưa có giờ mở cửa', 'tone' => 'warn', 'tip' => 'Travela chưa có giờ mở cửa của nơi này; nên kiểm tra trước khi đi.'];
            } elseif (empty($m['checked'])) {
                $tags[] = ['code' => 'hours_unchecked', 'text' => 'Giờ chưa kiểm tra', 'tone' => 'warn', 'tip' => 'Giờ mở cửa lấy từ nguồn tham khảo, chưa được xác minh trong ' . PlaceFacts::STALE_DAYS . ' ngày qua.'];
            }
        }
        $price = $it['priceMin'] ?? ($it['price'] ?? ($m['price'] ?? null));
        if ($price !== null && (float) $price > 0 && empty($it['booked']) && empty($m['checked'])) {
            $tags[] = ['code' => 'price_ref', 'text' => 'Giá tham khảo', 'tone' => 'info', 'tip' => 'Giá vé chưa được xác minh gần đây; giá thật có thể khác.'];
        }

        return $tags;
    }
}
