<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Api\ApiController;
use App\Models\Application;
use App\Models\ApplicationActivity;
use App\Services\ApplicationActivityService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ApplicationActivityController extends ApiController
{
    /**
     * List activities for an application (paginated, newest first).
     */
    public function index(Request $request, Application $application): JsonResponse
    {
        $activities = $application->activities()
            ->with('user:id,name,email,role')
            ->orderByDesc('created_at')
            ->paginate(20);

        $data = $activities->map(function (ApplicationActivity $a) {
            return [
                'id'            => $a->id,
                'activity_type' => $a->activity_type,
                'title'         => $a->title,
                'description'   => $a->description,
                'metadata'      => $a->metadata,
                'created_at'    => $a->created_at?->toISOString(),
                'user'          => $a->user ? [
                    'id'   => $a->user->id,
                    'name' => $a->user->name,
                    'role' => $a->user->role,
                ] : null,
            ];
        });

        return $this->paginated($activities, $data->toArray());
    }

    /**
     * Add a note to an application.
     */
    public function store(Request $request, Application $application): JsonResponse
    {
        $validated = $request->validate([
            'description' => 'required|string|max:5000',
            'title'       => 'nullable|string|max:255',
        ]);

        $activity = ApplicationActivityService::logNote(
            $application,
            $validated['description'],
            $request->user()->id
        );

        return $this->success([
            'id'            => $activity->id,
            'activity_type' => $activity->activity_type,
            'title'         => $activity->title,
            'description'   => $activity->description,
            'created_at'    => $activity->created_at?->toISOString(),
            'user'          => [
                'id'   => $request->user()->id,
                'name' => $request->user()->name,
                'role' => $request->user()->role,
            ],
        ], [], 201);
    }
}
