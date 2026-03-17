<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\TeacherAvailability;
use App\Models\TeacherAvailabilityException;
use App\Models\TeacherProfile;
use App\Services\TeacherScheduleService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class TeacherAvailabilityController extends Controller
{
    public function __construct(
        private TeacherScheduleService $scheduleService
    ) {}

    /**
     * GET /api/v1/teacher/availability
     * Get the authenticated teacher's availability + exceptions.
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $profile = TeacherProfile::firstOrCreate(
            ['user_id' => $user->id],
            ['display_name' => $user->name, 'max_hours_per_day' => 8, 'max_hours_per_week' => 40]
        );

        $availabilities = $profile->availabilities()
            ->orderBy('day_of_week')
            ->orderBy('start_time')
            ->get();

        $exceptions = $profile->availabilityExceptions()
            ->where('date', '>=', now()->subDays(7))
            ->orderBy('date')
            ->get();

        return response()->json([
            'profile'        => [
                'id'                => $profile->id,
                'max_hours_per_day' => $profile->max_hours_per_day,
                'max_hours_per_week' => $profile->max_hours_per_week,
            ],
            'availabilities' => $availabilities,
            'exceptions'     => $exceptions,
        ]);
    }

    /**
     * POST /api/v1/teacher/availability
     * Create or update recurring availability slots.
     */
    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'slots' => 'required|array|min:1',
            'slots.*.day_of_week'   => 'required|integer|between:0,6',
            'slots.*.start_time'    => 'required|date_format:H:i',
            'slots.*.end_time'      => 'required|date_format:H:i|after:slots.*.start_time',
            'slots.*.slot_duration_minutes' => 'nullable|integer|min:15|max:480',
            'slots.*.buffer_minutes' => 'nullable|integer|min:0|max:60',
            'slots.*.effective_from' => 'nullable|date',
            'slots.*.effective_until' => 'nullable|date|after_or_equal:slots.*.effective_from',
        ]);

        $user = $request->user();
        $profile = TeacherProfile::firstOrCreate(
            ['user_id' => $user->id],
            ['display_name' => $user->name, 'max_hours_per_day' => 8, 'max_hours_per_week' => 40]
        );

        $created = [];

        DB::beginTransaction();
        try {
            foreach ($request->slots as $slot) {
                $created[] = TeacherAvailability::create([
                    'teacher_profile_id'  => $profile->id,
                    'day_of_week'         => $slot['day_of_week'],
                    'start_time'          => $slot['start_time'],
                    'end_time'            => $slot['end_time'],
                    'is_recurring'        => true,
                    'slot_duration_minutes' => $slot['slot_duration_minutes'] ?? 60,
                    'buffer_minutes'      => $slot['buffer_minutes'] ?? 0,
                    'effective_from'      => $slot['effective_from'] ?? null,
                    'effective_until'     => $slot['effective_until'] ?? null,
                ]);
            }
            DB::commit();

            return response()->json([
                'message' => count($created) . ' availability slot(s) created.',
                'slots'   => $created,
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['message' => 'Failed to save availability.'], 500);
        }
    }

    /**
     * PUT /api/v1/teacher/availability/bulk
     * Replace all availability for a specific day.
     */
    public function bulkUpdate(Request $request): JsonResponse
    {
        $request->validate([
            'day_of_week' => 'required|integer|between:0,6',
            'slots'       => 'present|array',
            'slots.*.start_time' => 'required|date_format:H:i',
            'slots.*.end_time'   => 'required|date_format:H:i|after:slots.*.start_time',
            'slots.*.slot_duration_minutes' => 'nullable|integer|min:15|max:480',
            'slots.*.buffer_minutes' => 'nullable|integer|min:0|max:60',
        ]);

        $user = $request->user();
        $profile = TeacherProfile::firstOrCreate(
            ['user_id' => $user->id],
            ['display_name' => $user->name, 'max_hours_per_day' => 8, 'max_hours_per_week' => 40]
        );

        DB::beginTransaction();
        try {
            // Remove existing availability for this day
            $profile->availabilities()
                ->where('day_of_week', $request->day_of_week)
                ->delete();

            // Create new slots
            foreach ($request->slots as $slot) {
                TeacherAvailability::create([
                    'teacher_profile_id'    => $profile->id,
                    'day_of_week'           => $request->day_of_week,
                    'start_time'            => $slot['start_time'],
                    'end_time'              => $slot['end_time'],
                    'is_recurring'          => true,
                    'slot_duration_minutes'  => $slot['slot_duration_minutes'] ?? 60,
                    'buffer_minutes'        => $slot['buffer_minutes'] ?? 0,
                ]);
            }

            DB::commit();

            $updated = $profile->availabilities()
                ->where('day_of_week', $request->day_of_week)
                ->orderBy('start_time')
                ->get();

            return response()->json([
                'message' => 'Availability updated.',
                'slots'   => $updated,
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['message' => 'Failed to update availability.'], 500);
        }
    }

    /**
     * DELETE /api/v1/teacher/availability/{id}
     */
    public function destroy(Request $request, TeacherAvailability $availability): JsonResponse
    {
        $user = $request->user();
        $profile = TeacherProfile::where('user_id', $user->id)->first();

        if (!$profile || $availability->teacher_profile_id !== $profile->id) {
            return response()->json(['message' => 'Unauthorized.'], 403);
        }

        $availability->delete();

        return response()->json(['message' => 'Availability slot removed.']);
    }

    /**
     * POST /api/v1/teacher/availability/exceptions
     * Add a day-off or time exception.
     */
    public function storeException(Request $request): JsonResponse
    {
        $request->validate([
            'date'       => 'required|date|after_or_equal:today',
            'start_time' => 'nullable|date_format:H:i',
            'end_time'   => 'nullable|date_format:H:i|after:start_time',
            'type'       => 'required|in:unavailable,override',
            'reason'     => 'nullable|string|max:255',
        ]);

        $user = $request->user();
        $profile = TeacherProfile::firstOrCreate(
            ['user_id' => $user->id],
            ['display_name' => $user->name, 'max_hours_per_day' => 8, 'max_hours_per_week' => 40]
        );

        $exception = TeacherAvailabilityException::create([
            'teacher_profile_id' => $profile->id,
            'date'               => $request->date,
            'start_time'         => $request->start_time,
            'end_time'           => $request->end_time,
            'type'               => $request->type,
            'reason'             => $request->reason,
        ]);

        return response()->json([
            'message'   => 'Exception added.',
            'exception' => $exception,
        ], 201);
    }

    /**
     * DELETE /api/v1/teacher/availability/exceptions/{id}
     */
    public function destroyException(Request $request, TeacherAvailabilityException $exception): JsonResponse
    {
        $user = $request->user();
        $profile = TeacherProfile::where('user_id', $user->id)->first();

        if (!$profile || $exception->teacher_profile_id !== $profile->id) {
            return response()->json(['message' => 'Unauthorized.'], 403);
        }

        $exception->delete();

        return response()->json(['message' => 'Exception removed.']);
    }

    /**
     * PUT /api/v1/teacher/availability/settings
     * Update teacher profile settings (max hours etc).
     */
    public function updateSettings(Request $request): JsonResponse
    {
        $request->validate([
            'max_hours_per_day'  => 'required|integer|min:1|max:24',
            'max_hours_per_week' => 'required|integer|min:1|max:168',
        ]);

        $user = $request->user();
        $profile = TeacherProfile::firstOrCreate(
            ['user_id' => $user->id],
            ['display_name' => $user->name]
        );

        $profile->update($request->only('max_hours_per_day', 'max_hours_per_week'));

        return response()->json([
            'message' => 'Settings updated.',
            'profile' => $profile,
        ]);
    }

    /**
     * GET /api/v1/teacher/schedule
     * Get the teacher's schedule summary for a date range.
     */
    public function schedule(Request $request): JsonResponse
    {
        $request->validate([
            'date_from' => 'nullable|date',
            'date_to'   => 'nullable|date',
        ]);

        $user = $request->user();
        $dateFrom = Carbon::parse($request->date_from ?? now()->startOfWeek());
        $dateTo = Carbon::parse($request->date_to ?? now()->endOfWeek());

        $schedule = $this->scheduleService->getTeacherSchedule($user->id, $dateFrom, $dateTo);

        return response()->json($schedule);
    }

    /**
     * GET /api/v1/admin/teachers/{userId}/availability
     * Admin view of a teacher's availability.
     */
    public function adminIndex(Request $request, int $userId): JsonResponse
    {
        $user = \App\Models\User::find($userId);
        $profile = TeacherProfile::where('user_id', $userId)->first();
        $teacherRecord = \App\Models\Teacher::where('user_id', $userId)->first();

        // Resolve avatar
        $avatarUrl = null;
        if ($teacherRecord?->image_path) {
            $avatarUrl = '/storage/' . $teacherRecord->image_path;
        } elseif ($user?->avatar_path) {
            $avatarUrl = '/storage/' . $user->avatar_path;
        }

        if (!$profile) {
            return response()->json([
                'teacher'        => $user ? ['id' => $user->id, 'name' => $user->name, 'email' => $user->email, 'avatar_url' => $avatarUrl] : null,
                'profile'        => null,
                'availabilities' => [],
                'exceptions'     => [],
                'schedule'       => null,
            ]);
        }

        $availabilities = $profile->availabilities()
            ->orderBy('day_of_week')
            ->orderBy('start_time')
            ->get();

        $exceptions = $profile->availabilityExceptions()
            ->where('date', '>=', now()->subDays(7))
            ->orderBy('date')
            ->get();

        $dateFrom = Carbon::parse($request->date_from ?? now()->startOfWeek());
        $dateTo = Carbon::parse($request->date_to ?? now()->endOfWeek()->addDays(7));

        $schedule = $this->scheduleService->getTeacherSchedule($userId, $dateFrom, $dateTo);

        // Get services this teacher is eligible for
        $services = \App\Models\Service::where('booking_mode', 'flexible_booking')
            ->where('availability', true)
            ->where(function ($q) use ($userId) {
                $q->whereJsonContains('teacher_ids', $userId)
                  ->orWhere('instructor_id', $userId)
                  ->orWhereNull('teacher_ids');
            })
            ->select('id', 'service_name', '_type', 'booking_mode', 'session_duration_minutes', 'max_participants')
            ->get();

        return response()->json([
            'teacher' => [
                'id'         => $user?->id,
                'name'       => $user?->name,
                'email'      => $user?->email,
                'avatar_url' => $avatarUrl,
            ],
            'profile'        => $profile,
            'availabilities' => $availabilities,
            'exceptions'     => $exceptions,
            'schedule'       => $schedule,
            'services'       => $services,
        ]);
    }
}
