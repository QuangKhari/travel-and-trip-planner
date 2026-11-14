<?php

namespace App\Http\Controllers\admin;

use App\Http\Controllers\Controller;
use App\Services\Planner\PlannerAnalytics;
use Illuminate\Http\Request;

class PlannerAnalyticsController extends Controller
{
    public function index(Request $request, PlannerAnalytics $a)
    {
        $days = in_array((int) $request->query('days'), [7, 30, 90, 365], true) ? (int) $request->query('days') : 30;

        return view('admin.planner-analytics', ['title' => 'Phân tích Trip Planner', 'd' => $a->build($days)]);
    }
}
