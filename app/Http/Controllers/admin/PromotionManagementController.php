<?php

namespace App\Http\Controllers\admin;

use App\Http\Controllers\Controller;
use App\Models\admin\PromotionModel;
use App\Support\AuditLog;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;

/**
 * Mã giảm giá (D-04): mã duy nhất (không phân biệt hoa thường), % giảm, giảm tối đa, đơn tối thiểu,
 * tổng số lượt, lượt / người, hạn dùng tính cả ngày cuối. Mã đã có đơn dùng thì "xóa" = vô hiệu hóa (giữ lịch sử).
 */
class PromotionManagementController extends Controller
{
    private PromotionModel $promotion;

    public function __construct()
    {
        parent::__construct();
        $this->promotion = new PromotionModel();
    }

    public function index(Request $request)
    {
        $title = 'Khuyến mãi';
        $list_promotion = collect($this->promotion->getPromotion());
        $usage = Schema::hasTable('tbl_promotion_usage')
            ? DB::table('tbl_promotion_usage as u')->join('tbl_booking as b', 'b.bookingId', '=', 'u.bookingId')
                ->where('b.bookingStatus', '!=', 'c')
                ->selectRaw('u.promotionId, COUNT(*) n, COALESCE(SUM(u.discountAmount),0) total')->groupBy('u.promotionId')->get()->keyBy('promotionId')
            : collect();
        $today = now()->toDateString();
        foreach ($list_promotion as $p) {
            $p->used = (int) ($usage[$p->promotionId]->n ?? 0);
            $p->discountTotal = (int) ($usage[$p->promotionId]->total ?? 0);
            $p->state = $p->status !== 'y' ? 'off' : ($p->endDate < $today ? 'expired' : ($p->startDate > $today ? 'scheduled' : ((int) $p->quantity <= 0 ? 'empty' : 'live')));
        }
        $stats = [
            'live' => $list_promotion->where('state', 'live')->count(),
            'used' => $list_promotion->sum('used'),
            'discount' => $list_promotion->sum('discountTotal'),
            'expiring' => $list_promotion->where('state', 'live')->filter(fn($p) => $p->endDate <= now()->addDays(7)->toDateString())->count(),
        ];

        return view('admin.promotion', compact('title', 'list_promotion', 'stats'));
    }

    private function rules(?int $ignoreId = null): array
    {
        return [
            'code' => ['required', 'string', 'max:50', 'regex:/^[A-Z0-9_-]+$/', Rule::unique('tbl_promotion', 'code')->ignore($ignoreId, 'promotionId')],
            'description' => 'required|string|max:255',
            'discount' => 'required|numeric|min:1|max:100',
            'maxDiscount' => 'nullable|integer|min:0|max:1000000000',
            'minOrder' => 'nullable|integer|min:0|max:10000000000',
            'perUserLimit' => 'nullable|integer|min:0|max:100',
            'quantity' => ($ignoreId ? 'required|integer|min:0' : 'required|integer|min:1') . '|max:100000',
            'startDate' => 'required|date',
            'endDate' => 'required|date|after_or_equal:startDate',   // cho phép khuyến mãi trong đúng một ngày
        ];
    }

    private const MESSAGES = [
        'code.unique' => 'Mã giảm giá này đã tồn tại.',
        'code.regex' => 'Mã chỉ gồm chữ in hoa không dấu, số, gạch ngang, gạch dưới.',
        'endDate.after_or_equal' => 'Ngày kết thúc phải bằng hoặc sau ngày bắt đầu.',
        'discount.*' => 'Phần trăm giảm phải từ 1 đến 100.',
    ];

    private function payload(Request $r): array
    {
        $data = [
            'code' => strtoupper(trim((string) $r->code)),
            'description' => trim((string) $r->description),
            'discount' => (float) $r->discount,
            'quantity' => (int) $r->quantity,
            'startDate' => $r->startDate,
            'endDate' => $r->endDate,
        ];
        if (Schema::hasColumn('tbl_promotion', 'maxDiscount')) {
            $data['maxDiscount'] = $r->filled('maxDiscount') && (int) $r->maxDiscount > 0 ? (int) $r->maxDiscount : null;
            $data['minOrder'] = (int) ($r->minOrder ?? 0);
            $data['perUserLimit'] = $r->filled('perUserLimit') ? (int) $r->perUserLimit : null;
        }

        return $data;
    }

    public function addPromotion(Request $request)
    {
        // Chuẩn hóa mã TRƯỚC khi kiểm tra trùng: "summer" và "SUMMER" là một mã
        $request->merge(['code' => strtoupper(trim((string) $request->input('code')))]);
        $request->validate($this->rules(), self::MESSAGES);
        $data = $this->payload($request) + ['status' => 'y'];

        try {
            $this->promotion->addPromotion($data);
        } catch (QueryException $e) {
            if ((int) ($e->errorInfo[1] ?? 0) === 1062) {
                return back()->withInput()->with('error', 'Mã giảm giá này đã được sử dụng.');
            }
            report($e);

            return back()->withInput()->with('error', 'Không thể thêm mã giảm giá lúc này. Vui lòng thử lại sau.');
        }
        $id = DB::table('tbl_promotion')->where('code', $data['code'])->value('promotionId');
        AuditLog::record('promotion.create', 'promotion', $id, 'Tạo mã ' . $data['code'] . ' giảm ' . $data['discount'] . '%', null, $data);

        return redirect()->route('admin.promotion')->with('success', 'Đã tạo mã ' . $data['code'] . '.');
    }

    public function getPromotionEdit($id)
    {
        $promotion = $this->promotion->getPromotionById($id);
        if (!$promotion) {
            return redirect()->route('admin.promotion')->with('error', 'Không tìm thấy mã giảm giá.');
        }
        $title = 'Sửa mã ' . $promotion->code;
        $usage = Schema::hasTable('tbl_promotion_usage')
            ? DB::table('tbl_promotion_usage as u')->join('tbl_booking as b', 'b.bookingId', '=', 'u.bookingId')
                ->join('tbl_users as us', 'us.userId', '=', 'u.userId')
                ->where('u.promotionId', $promotion->promotionId)->orderByDesc('u.usageId')->limit(20)
                ->get(['u.*', 'b.bookingCode', 'b.bookingStatus', 'b.totalPrice', 'us.fullName as userName'])
            : collect();

        return view('admin.edit-promotion', compact('promotion', 'title', 'usage'));
    }

    public function editPromotion(Request $request)
    {
        $request->merge(['code' => strtoupper(trim((string) $request->input('code')))]);
        $id = (int) $request->input('promotionId');
        $request->validate(['promotionId' => 'required|integer|exists:tbl_promotion,promotionId', 'status' => 'required|in:y,n'] + $this->rules($id), self::MESSAGES);
        $before = (array) DB::table('tbl_promotion')->where('promotionId', $id)->first();
        $data = $this->payload($request) + ['status' => $request->status];

        try {
            $this->promotion->updatePromotion($id, $data);
        } catch (QueryException $e) {
            if ((int) ($e->errorInfo[1] ?? 0) === 1062) {
                return back()->withInput()->with('error', 'Mã giảm giá này đã được sử dụng.');
            }
            report($e);

            return back()->withInput()->with('error', 'Không thể cập nhật mã giảm giá.');
        }
        [$b, $a] = AuditLog::diff(array_intersect_key($before, $data), $data);
        if ($a) {
            AuditLog::record('promotion.update', 'promotion', $id, 'Sửa mã ' . $data['code'], $b, $a);
        }

        return redirect()->route('admin.promotion')->with('success', 'Đã cập nhật mã ' . $data['code'] . '.');
    }

    public function deletePromotion(Request $request)
    {
        $request->validate(['promotionId' => 'required|integer|min:1|exists:tbl_promotion,promotionId']);
        $id = (int) $request->input('promotionId');
        $before = DB::table('tbl_promotion')->where('promotionId', $id)->first();
        $delete = $this->promotion->deletePromotion($id);
        $still = DB::table('tbl_promotion')->where('promotionId', $id)->exists();
        $msg = !$delete ? 'Xóa mã thất bại.' : ($still ? 'Mã đã có đơn dùng nên được vô hiệu hóa (giữ lịch sử) thay vì xóa.' : 'Đã xóa mã ' . $before->code . '.');
        if ($delete) {
            AuditLog::record('promotion.delete', 'promotion', $id, ($still ? 'Vô hiệu hóa mã ' : 'Xóa mã ') . $before->code, (array) $before, $still ? ['status' => 'n'] : null);
        }

        if ($request->ajax()) {
            return response()->json(['success' => (bool) $delete, 'message' => $msg], 200, [], JSON_UNESCAPED_UNICODE);
        }

        return redirect()->route('admin.promotion')->with($delete ? 'success' : 'error', $msg);
    }
}
