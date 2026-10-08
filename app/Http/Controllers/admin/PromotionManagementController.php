<?php

namespace App\Http\Controllers\admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\admin\PromotionModel;
use Illuminate\Validation\Rule;

class PromotionManagementController extends Controller
{
    private $promotion;

    public function __construct()
    {
        parent::__construct();
        $this->promotion = new PromotionModel();
    }

    // Danh sách promotion
    public function index()
    {
        $title = 'Quản lý Voucher';

        $list_promotion = $this->promotion->getPromotion();

        return view('admin.promotion', compact(
            'title',
            'list_promotion'
        ));
    }

    // Thêm promotion
    public function addPromotion(Request $request)
    {
        // Chuẩn hóa mã TRƯỚC khi kiểm tra trùng: "summer" và "SUMMER" là một mã
        $request->merge(['code' => strtoupper(trim((string) $request->input('code')))]);

        $request->validate([
            'code'        => ['required', 'string', 'max:50', Rule::unique('tbl_promotion', 'code')],
            'description' => 'required|string|max:255',
            'discount'    => 'required|numeric|min:1|max:100',
            'quantity'    => 'required|integer|min:1',
            'startDate'   => 'required|date',
            'endDate'     => 'required|date|after_or_equal:startDate',   // cho phép khuyến mãi trong đúng một ngày
        ], [
            'code.unique' => 'Mã giảm giá này đã tồn tại.',
        ]);

        $data = [
            'code' => strtoupper($request->code),
            'description' => $request->description,
            'discount' => $request->discount,
            'quantity' => $request->quantity,
            'startDate' => $request->startDate,
            'endDate' => $request->endDate,
            'status' => 'y'
        ];

        $insert = $this->promotion->addPromotion($data);

        if ($insert) {
            return redirect()
                ->back()
                ->with('success', 'Thêm Promotion thành công');
        }

        return redirect()
            ->back()
            ->with('error', 'Thêm Promotion thất bại');
    }

    // Lấy thông tin promotion để sửa
    public function getPromotionEdit($id)
    {
        $promotion = $this->promotion->getPromotionById($id);

        if (!$promotion) {
            return redirect()
                ->route('admin.promotion')
                ->with('error', 'Không tìm thấy voucher!');
        }

        $title = 'Sửa Promotion';

        return view(
            'admin.edit-promotion',
            compact('promotion', 'title')
        );
    }

    // Cập nhật promotion
    public function editPromotion(Request $request)
    {
        $request->merge(['code' => strtoupper(trim((string) $request->input('code')))]);

        $request->validate([
            'promotionId' => 'required|integer|exists:tbl_promotion,promotionId',
            'code'        => [
                'required',
                'string',
                'max:50',
                Rule::unique('tbl_promotion', 'code')->ignore($request->input('promotionId'), 'promotionId'),
            ],
            'description' => 'required|string|max:255',
            'discount'    => 'required|numeric|min:1|max:100',
            'quantity'    => 'required|integer|min:0',     // 0 = hết lượt, admin được phép đặt
            'startDate'   => 'required|date',
            'endDate'     => 'required|date|after_or_equal:startDate',
            'status'      => 'required|in:y,n',
        ], [
            'code.unique' => 'Mã giảm giá này đã tồn tại.',
        ]);

        $result = $this->promotion->updatePromotion(
            $request->promotionId,
            [
                'code' => strtoupper($request->code),
                'description' => $request->description,
                'discount' => $request->discount,
                'quantity' => $request->quantity,
                'startDate' => $request->startDate,
                'endDate' => $request->endDate,
                'status' => $request->status
            ]
        );

        return redirect()
            ->route('admin.promotion')
            ->with(
                $result ? 'success' : 'error',
                $result
                    ? 'Cập nhật Promotion thành công'
                    : 'Cập nhật Promotion thất bại'
            );
    }

    // Xóa promotion
    public function deletePromotion(Request $request)
    {
        $request->validate([
            'promotionId' => 'required|integer|min:1|exists:tbl_promotion,promotionId',
        ]);

        $promotionId = (int) $request->input('promotionId');

        $delete = $this->promotion->deletePromotion(
            $promotionId
        );

        // Nếu request là AJAX → trả JSON chuẩn
        if ($request->ajax()) {
            return response()->json([
                'success' => $delete ? true : false,
                'message' => $delete
                    ? 'Xóa Promotion thành công'
                    : 'Xóa Promotion thất bại'
            ], 200, [], JSON_UNESCAPED_UNICODE);
        }

        return redirect()
            ->route('admin.promotion')
            ->with(
                $delete ? 'success' : 'error',
                $delete ? 'Xóa Promotion thành công' : 'Xóa Promotion thất bại'
            );
    }
}
