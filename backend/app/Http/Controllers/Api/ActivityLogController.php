<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\HandlesIndexQueries;
use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use App\Support\Permissions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Spatie\Activitylog\Models\Activity;

class ActivityLogController extends Controller
{
    use HandlesIndexQueries;

    public function index(Request $request): JsonResponse
    {
        $request->user()->can(Permissions::ACTIVITY_VIEW) || abort(403);

        $query = Activity::query()->with('causer');

        if ($logName = $request->query('log_name')) {
            $query->where('log_name', $logName);
        }

        if ($userId = $request->query('user_id')) {
            $query->where('causer_id', $userId);
        }

        if ($subjectType = $request->query('subject_type')) {
            $query->where('subject_type', 'like', '%'.$subjectType);
        }

        if ($subjectId = $request->query('subject_id')) {
            $query->where('subject_id', $subjectId);
        }

        $this->applySearch($query, $request, ['description']);
        $this->applyDateRange($query, $request, 'created_at');

        $paginator = $query->latest('id')->paginate(
            max(1, min((int) $request->query('per_page', 25), 200))
        )->withQueryString();

        return ApiResponse::success(
            collect($paginator->items())->map(fn (Activity $activity) => [
                'id' => $activity->id,
                'log_name' => $activity->log_name,
                'description' => $activity->description,
                'user' => $activity->causer?->name ?? 'System',
                'subject_type' => $activity->subject_type ? class_basename($activity->subject_type) : null,
                'subject_id' => $activity->subject_id,
                'properties' => $activity->properties,
                'created_at' => $activity->created_at?->toIso8601String(),
            ]),
            null,
            $this->paginationMeta($paginator) + [
                'log_names' => Activity::query()->distinct()->orderBy('log_name')->pluck('log_name'),
            ]
        );
    }
}
