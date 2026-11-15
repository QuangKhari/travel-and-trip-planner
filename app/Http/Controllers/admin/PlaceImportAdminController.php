<?php

namespace App\Http\Controllers\admin;

use App\Http\Controllers\Controller;
use App\Services\Content\PlaceImport;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Admin > Nhập địa điểm: nạp tệp thành lô, xem từng dòng, duyệt hoặc loại (Đợt C mục 19). */
class PlaceImportAdminController extends Controller
{
    public function index()
    {
        $title = 'Nhập địa điểm';
        $ready = PlaceImport::ready();
        $batches = $ready ? DB::table('tbl_import_batches as b')
            ->leftJoin('tbl_admin as a', 'a.adminId', '=', 'b.createdBy')
            ->select('b.*', 'a.fullName as byName')
            ->selectSub(DB::table('tbl_import_rows')->whereColumn('batchId', 'b.batchId')->where('status', 'pending')->selectRaw('COUNT(*)'), 'pending')
            ->selectSub(DB::table('tbl_import_rows')->whereColumn('batchId', 'b.batchId')->whereIn('status', ['created', 'merged'])->selectRaw('COUNT(*)'), 'done')
            ->orderByDesc('b.batchId')->limit(50)->get() : collect();

        return view('admin.place-import.index', compact('title', 'ready', 'batches'));
    }

    public function template()
    {
        return response(PlaceImport::template(), 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="travela-dia-diem-mau.csv"',
        ]);
    }

    public function store(Request $request)
    {
        $request->validate([
            'file' => ['required', 'file', 'max:4096', 'mimes:csv,txt,json'],
            'note' => ['nullable', 'string', 'max:255'],
        ], ['file.mimes' => 'Chỉ nhận tệp .csv hoặc .json.', 'file.max' => 'Tệp tối đa 4 MB.']);
        $f = $request->file('file');
        $r = PlaceImport::stage((string) file_get_contents($f->getRealPath()), $f->getClientOriginalName(), (int) session('adminId'), $request->input('note'));
        if ($r['error']) {
            return back()->withErrors(['file' => $r['error']]);
        }
        toastr()->success(sprintf('Đã nạp %d dòng: %d lỗi, %d có thể trùng. Xem và duyệt bên dưới.', $r['total'], $r['bad'], $r['dup']));

        return redirect()->route('admin.place-import.show', ['id' => $r['batchId']]);
    }

    public function show(Request $request, int $id)
    {
        $batch = DB::table('tbl_import_batches')->where('batchId', $id)->first() ?? abort(404);
        $title = 'Lô nhập #' . $id;
        $filter = (string) $request->query('loc', 'pending');
        $q = DB::table('tbl_import_rows as r')->where('r.batchId', $id)
            ->leftJoin('tbl_destinations as d', 'd.destinationId', '=', 'r.destinationId')
            ->leftJoin('tbl_external_places as p', 'p.placeId', '=', 'r.matchPlaceId')
            ->select('r.*', 'd.name as destName', 'p.name as matchName');
        match ($filter) {
            'error' => $q->where('r.level', 'error'),
            'warn' => $q->where('r.level', 'warn')->where('r.status', 'pending'),
            'dup' => $q->whereNotNull('r.matchPlaceId'),
            'done' => $q->whereIn('r.status', ['created', 'merged', 'rejected']),
            'all' => null,
            default => $q->where('r.status', 'pending'),
        };
        $rows = $q->orderBy('r.line')->paginate(100)->withQueryString();
        $counts = DB::table('tbl_import_rows')->where('batchId', $id)
            ->selectRaw("SUM(status='pending') as pending, SUM(level='error') as error, SUM(level='warn' AND status='pending') as warn, SUM(matchPlaceId IS NOT NULL) as dup, SUM(status IN ('created','merged','rejected')) as done, COUNT(*) as total")
            ->first();
        $coverage = PlaceImport::coverage($id);

        return view('admin.place-import.show', compact('title', 'batch', 'rows', 'counts', 'filter', 'coverage'));
    }

    public function decide(Request $request, int $id)
    {
        $v = $request->validate([
            'rows' => ['required', 'array', 'max:500'],
            'rows.*' => ['integer'],
            'action' => ['required', 'in:approve,reject'],
            'publish' => ['nullable', 'boolean'],
            'note' => ['nullable', 'string', 'max:255'],
        ], ['rows.required' => 'Chọn ít nhất một dòng.']);
        DB::table('tbl_import_batches')->where('batchId', $id)->exists() || abort(404);
        $me = (int) session('adminId');
        if ($v['action'] === 'approve') {
            $r = PlaceImport::approve($id, $v['rows'], $me, (bool) ($v['publish'] ?? false));
            toastr()->success(sprintf('Tạo %d địa điểm, bổ sung %d địa điểm có sẵn%s.', $r['created'], $r['merged'], $r['skipped'] ? ', bỏ qua ' . $r['skipped'] . ' dòng lỗi hoặc đã xử lý' : ''));
        } else {
            if (trim((string) ($v['note'] ?? '')) === '') {
                return back()->withErrors(['note' => 'Ghi lý do loại để người nhập dữ liệu sửa lại.']);
            }
            $n = PlaceImport::reject($id, $v['rows'], $me, (string) $v['note']);
            toastr()->success("Đã loại $n dòng.");
        }

        return back();
    }
}
