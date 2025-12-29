<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Spatie\Activitylog\Models\Activity;
use Illuminate\Support\Facades\DB;

class ActivityLogController extends Controller
{
    public function index(Request $request)
    {
        $perPage = (int) $request->query('per_page', 50);
        $logName = $request->query('log_name');
        $causerId = $request->query('causer_id');
        $causerType = $request->query('causer_type');
        $dateFrom = $request->query('date_from');
        $dateTo = $request->query('date_to');
        $search = $request->query('search');

        $query = Activity::with(['causer', 'subject'])
            ->orderByDesc('created_at');

        if ($logName) {
            $query->where('log_name', $logName);
        }

        if ($causerId && $causerType) {
            $query->where('causer_id', $causerId)
                  ->where('causer_type', $causerType);
        }

        if ($dateFrom) {
            $query->whereDate('created_at', '>=', $dateFrom);
        }

        if ($dateTo) {
            $query->whereDate('created_at', '<=', $dateTo);
        }

        if ($search) {
            $query->where(function($q) use ($search) {
                $q->where('description', 'like', "%{$search}%")
                  ->orWhere('properties', 'like', "%{$search}%");
            });
        }

        $logs = $query->paginate($perPage);

        return response()->json($logs);
    }

    public function show($id)
    {
        $activity = Activity::with(['causer', 'subject'])->findOrFail($id);

        return response()->json([
            'id' => $activity->id,
            'log_name' => $activity->log_name,
            'description' => $activity->description,
            'event' => $activity->event,
            'causer' => $activity->causer,
            'subject' => $activity->subject,
            'properties' => $activity->properties,
            'created_at' => $activity->created_at,
        ]);
    }

    public function stats()
    {
        $stats = [
            'total_logs' => Activity::count(),
            'today_logs' => Activity::whereDate('created_at', today())->count(),
            'this_week_logs' => Activity::whereBetween('created_at', [now()->startOfWeek(), now()->endOfWeek()])->count(),
            'this_month_logs' => Activity::whereMonth('created_at', now()->month)->count(),

            'by_log_name' => Activity::select('log_name', DB::raw('count(*) as count'))
                ->groupBy('log_name')
                ->orderByDesc('count')
                ->get(),

            'by_event' => Activity::select('event', DB::raw('count(*) as count'))
                ->whereNotNull('event')
                ->groupBy('event')
                ->orderByDesc('count')
                ->get(),

            'recent_activities' => Activity::with(['causer', 'subject'])
                ->orderByDesc('created_at')
                ->limit(10)
                ->get(),
        ];

        return response()->json($stats);
    }

    public function userActivity(Request $request, $userId)
    {
        $userType = $request->query('user_type', 'App\\Models\\User');
        $perPage = (int) $request->query('per_page', 20);

        $logs = Activity::where('causer_id', $userId)
            ->where('causer_type', $userType)
            ->with(['subject'])
            ->orderByDesc('created_at')
            ->paginate($perPage);

        return response()->json($logs);
    }
}
