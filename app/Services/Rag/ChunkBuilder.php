<?php

namespace App\Services\Rag;

use App\Services\Content\ContentCatalog;
use App\Services\Content\SearchCatalog;
use App\Services\EventDateService;
use Illuminate\Support\Facades\DB;

/**
 * G2: gom nội dung ĐÃ DUYỆT (published; điểm đến thì isActive = 1) thành các đoạn kèm metadata.
 * Không bao giờ đọc bản nháp: đây là chốt chặn đầu tiên của quy tắc "chỉ dùng nội dung published" (tài liệu mục 9.4).
 * Không ghi DB: ChunkIndexer lo việc lưu và nhúng.
 */
class ChunkBuilder
{
    public const TYPES = ['guide', 'destination', 'food', 'experience', 'event', 'itinerary'];

    public function __construct(private Chunker $chunker, private EventDateService $dates) {}

    public static function make(): self
    {
        return new self(new Chunker((int) config('rag.chunk.min_words', 120), (int) config('rag.chunk.max_words', 380)), new EventDateService());
    }

    /**
     * @param list<string>|null $only giới hạn loại nguồn (mặc định tất cả)
     * @return list<array<string,mixed>>
     */
    public function build(?array $only = null): array
    {
        $only ??= self::TYPES;
        $rows = [];
        foreach ($only as $type) {
            $method = 'from' . ucfirst($type);
            foreach ($this->$method() as $r) {
                $rows[] = $r;
            }
        }

        return $rows;
    }

    private static function plain(?string $html): string
    {
        $t = html_entity_decode(strip_tags((string) $html), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return trim(preg_replace('/[ \t]+/u', ' ', str_replace(["\r\n", "\r"], "\n", $t)) ?? '');
    }

    private static function day(?string $v): ?string
    {
        return $v ? substr((string) $v, 0, 10) : null;
    }

    /** @return list<array<string,mixed>> */
    private function pack(string $type, int $id, string $title, string $text, string $url, ?int $dest, ?int $month, ?string $updatedOn): array
    {
        $parts = $this->chunker->split($title, $text);
        $out = [];
        foreach ($parts as $i => $p) {
            $out[] = [
                'refType' => $type,
                'refId' => $id,
                'seq' => $i,
                'destinationId' => $dest,
                'month' => $month,
                'title' => mb_substr($p['title'], 0, 255),
                'text' => $p['text'],
                'ftext' => SearchCatalog::fold($p['title'] . ' ' . $p['text']),
                'url' => $url,
                'updatedOn' => $updatedOn,
                'hash' => hash('sha256', $p['title'] . "\n" . $p['text']),
            ];
        }

        return $out;
    }

    private function fromGuide(): array
    {
        $out = [];
        $rows = DB::table('tbl_guides as g')->leftJoin('tbl_guide_categories as c', 'c.categoryId', '=', 'g.categoryId')
            ->where('g.reviewStatus', 'published')->orderBy('g.guideId')->get(['g.*', 'c.slug as catSlug']);
        foreach ($rows as $g) {
            $url = route('guides.show', ['category' => $g->catSlug ?: 'khac', 'slug' => $g->slug], false);
            $upd = self::day($g->updatedOn ?? null) ?? self::day($g->updatedAt ?? null) ?? self::day($g->createdAt ?? null);
            array_push($out, ...$this->pack('guide', (int) $g->guideId, $g->title, (string) $g->body, $url, null, null, $upd));
        }

        return $out;
    }

    private function fromDestination(): array
    {
        $out = [];
        foreach (DB::table('tbl_destinations')->where('isActive', 1)->orderBy('destinationId')->get() as $d) {
            $intro = trim((string) ($d->wikiExtract ?: $d->description));
            if ($intro === '') {
                continue;
            }
            $region = ContentCatalog::REGIONS[$d->domain] ?? null;
            $head = $d->name . ' thuộc ' . $d->province . ($region ? ', ' . mb_strtolower($region) : '') . '.';
            $url = route('destination.show', ['slug' => $d->slug], false);
            $upd = self::day($d->wikiSyncedAt ?? null) ?? self::day($d->updatedAt ?? null) ?? self::day($d->createdAt ?? null);
            array_push($out, ...$this->pack('destination', (int) $d->destinationId, $d->name, $head . "\n\n" . self::plain($intro), $url, (int) $d->destinationId, null, $upd));
        }

        return $out;
    }

    private function fromFood(): array
    {
        $out = [];
        $rows = DB::table('tbl_foods as f')->leftJoin('tbl_destinations as d', 'd.destinationId', '=', 'f.destinationId')
            ->where('f.reviewStatus', 'published')->orderBy('f.foodId')->get(['f.*', 'd.name as destName']);
        foreach ($rows as $f) {
            $text = self::plain($f->description);
            if ($text === '') {
                continue;
            }
            $facts = array_filter([$f->category ? 'Nhóm món: ' . $f->category . '.' : null, $f->destName ? 'Xuất xứ: ' . $f->destName . '.' : null]);
            $url = route('foods.show', ['slug' => $f->slug], false);
            $upd = self::day($f->updatedAt ?? null) ?? self::day($f->createdAt ?? null);
            array_push($out, ...$this->pack('food', (int) $f->foodId, 'Món ' . $f->name, $text . ($facts ? "\n" . implode(' ', $facts) : ''), $url, $f->destinationId ? (int) $f->destinationId : null, null, $upd));
        }

        return $out;
    }

    /** Tháng phù hợp của trải nghiệm viết thành câu (chỉ khi có đủ monthFrom và monthTo). */
    private static function seasonSentence(object $x): ?string
    {
        if (empty($x->monthFrom) || empty($x->monthTo)) {
            return null;
        }

        return $x->monthFrom === $x->monthTo ? 'Phù hợp nhất vào tháng ' . $x->monthFrom . '.' : 'Phù hợp từ tháng ' . $x->monthFrom . ' đến tháng ' . $x->monthTo . '.';
    }

    private function fromExperience(): array
    {
        $out = [];
        $rows = DB::table('tbl_experiences as x')->leftJoin('tbl_destinations as d', 'd.destinationId', '=', 'x.destinationId')
            ->where('x.reviewStatus', 'published')->orderBy('x.experienceId')->get(['x.*', 'd.name as destName']);
        $level = ['easy' => 'dễ', 'medium' => 'vừa sức', 'hard' => 'khó'];
        foreach ($rows as $x) {
            $text = self::plain($x->description);
            if ($text === '') {
                continue;
            }
            $facts = array_filter([
                $x->category ? 'Loại: ' . $x->category . '.' : null,
                $x->destName ? 'Địa điểm: ' . $x->destName . '.' : null,
                $x->duration ? 'Thời lượng: ' . $x->duration . '.' : ($x->durationMinutes ? 'Thời lượng khoảng ' . $x->durationMinutes . ' phút.' : null),
                !empty($x->difficulty) ? 'Độ khó: ' . ($level[$x->difficulty] ?? $x->difficulty) . '.' : null,
                $x->indoor === null ? null : ($x->indoor ? 'Diễn ra trong nhà.' : 'Diễn ra ngoài trời.'),
                self::seasonSentence($x),
                !empty($x->priceNote) ? 'Giá (tham khảo): ' . $x->priceNote . '.' : null,
            ]);
            $url = route('experiences.show', ['slug' => $x->slug], false);
            $upd = self::day($x->updatedAt ?? null) ?? self::day($x->createdAt ?? null);
            array_push($out, ...$this->pack('experience', (int) $x->experienceId, $x->name, $text . ($facts ? "\n" . implode(' ', $facts) : ''), $url, $x->destinationId ? (int) $x->destinationId : null, null, $upd));
        }

        return $out;
    }

    /** Quy tắc ngày của lễ hội viết thành câu (chỉ là QUY TẮC; ngày dương cụ thể luôn lấy từ EventDateService). */
    public static function eventRule(object $e): string
    {
        if (!empty($e->isLunar)) {
            $when = 'ngày ' . ($e->lunarDay ?: '?') . ($e->lunarMonth ? ' tháng ' . $e->lunarMonth : ' hằng tháng') . ' âm lịch';
        } else {
            $when = ($e->solarDay ? 'ngày ' . $e->solarDay . ' ' : '') . ($e->solarMonth ? 'tháng ' . $e->solarMonth . ' dương lịch' : 'một thời điểm trong năm');
        }

        return trim('Diễn ra vào ' . $when . ', kéo dài ' . max(1, (int) $e->durationDays) . ' ngày. ' . ($e->dateNote ?? ''));
    }

    private function fromEvent(): array
    {
        $out = [];
        $rows = DB::table('tbl_events as e')->leftJoin('tbl_destinations as d', 'd.destinationId', '=', 'e.destinationId')
            ->where('e.reviewStatus', 'published')->orderBy('e.eventId')->get(['e.*', 'd.name as destName']);
        foreach ($rows as $e) {
            $text = self::plain($e->description);
            if ($text === '') {
                continue;
            }
            $next = $this->dates->upcoming($e, null, 1)[0] ?? null;
            $month = $e->solarMonth ? (int) $e->solarMonth : ($next ? (int) substr($next['start'], 5, 2) : null);
            $where = $e->destName ? 'Địa điểm: ' . $e->destName . '.' : 'Lễ hội chung cả nước.';
            $url = route('events.show', ['slug' => $e->slug], false);
            $upd = self::day($e->updatedAt ?? null) ?? self::day($e->createdAt ?? null);
            array_push($out, ...$this->pack('event', (int) $e->eventId, $e->name, $text . "\n" . $where . ' ' . self::eventRule($e), $url, $e->destinationId ? (int) $e->destinationId : null, $month, $upd));
        }

        return $out;
    }

    private function fromItinerary(): array
    {
        $out = [];
        foreach (DB::table('tbl_itineraries')->where('reviewStatus', 'published')->orderBy('itineraryId')->get() as $i) {
            $items = DB::table('tbl_itinerary_items')->where('itineraryId', $i->itineraryId)->orderBy('dayNo')->orderBy('orderNo')->get();
            $lines = [self::plain($i->summary)];
            $dest = null;
            foreach ($items->groupBy('dayNo') as $day => $its) {
                $lines[] = 'Ngày ' . $day . ': ' . $its->pluck('label')->implode(', ') . '.';
                $dest ??= optional($its->firstWhere('refType', 'destination'))->refId;
            }
            $text = trim(implode("\n", array_filter($lines)));
            if ($text === '') {
                continue;
            }
            $url = route('itineraries.show', ['slug' => $i->slug], false);
            $upd = self::day($i->updatedAt ?? null) ?? self::day($i->createdAt ?? null);
            array_push($out, ...$this->pack('itinerary', (int) $i->itineraryId, $i->title . ' (' . $i->days . ' ngày)', $text, $url, $dest ? (int) $dest : null, null, $upd));
        }

        return $out;
    }
}
