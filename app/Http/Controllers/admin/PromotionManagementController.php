<?php

namespace App\Http\Controllers\admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\admin\PromotionModel;

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
            $request->validate([
        'code' => 'required',
        'description' => 'required',
        'discount' => 'required|numeric|min:1|max:100',
        'quantity' => 'required|numeric|min:1',
        'startDate' => 'required|date',
        'endDate' => 'required|date|after:startDate',
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

        return view('admin.edit-promotion',
        compact('promotion', 'title')
        );
    }

    // Cập nhật promotion
    public function editPromotion(Request $request)
    {
        $request->validate([
        'code' => 'required',
        'description' => 'required',
        'discount' => 'required|numeric|min:1|max:100',
        'quantity' => 'required|numeric|min:1',
        'startDate' => 'required|date',
        'endDate' => 'required|date|after:startDate',
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
        $delete = $this->promotion->deletePromotion(
        $request->promotionId
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
