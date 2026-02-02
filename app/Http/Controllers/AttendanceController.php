<?php

namespace App\Http\Controllers;

use App\Models\Attendance;
use App\Models\Lesson;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;

/**
 * Handles recording + approving lesson attendance.
 */
class AttendanceController extends Controller
{
    /* ───────────────── helpers ───────────────── */

    /**
     * All children that should attend the lesson
     * = direct children ∪ service-inherited children.
     */
    protected function childrenFor(Lesson $lesson)
    {
        $lesson->loadMissing([
            'children:id,child_name',
            'service.children:id,child_name',
        ]);

        return $lesson->children
                      ->merge($lesson->service?->children ?? collect())
                      ->unique('id')
                      ->values();
    }

    /**
     * Convenience – returns Y-m-d string of the lesson’s date.
     */
    protected function lessonDate(Lesson $lesson): string
    {
        return $lesson->start_time->toDateString();
    }

    /* ───────────────── tutor / parent marks one row ───────────────── */

    public function store(Request $request, Lesson $lesson)
    {
        //savving user roole in a vriable
        $userRole = $request->user()->role;
       
        $data = $request->validate([
            'child_id' => 'required|exists:children,id',
            'status'   => 'required|in:present,absent,late,excused',
            'notes'    => 'nullable|string|max:500',
            'date'     => 'nullable|date',
        ]);

        $date = $data['date']
            ? Carbon::parse($data['date'])->toDateString()
            : $this->lessonDate($lesson);

        // Check existing attendance row (if any)
        $existing = Attendance::where('lesson_id', $lesson->id)
            ->where('child_id', $data['child_id'])
            ->whereDate('date', $date)
            ->first();

        if ($userRole !== 'admin' && $existing && $existing->approved) {
            // If already approved, do not allow changes by non-admin users
          
            return redirect()->back()
                ->with('error', 'Attendance has already been approved and cannot be changed.');
        }

        Attendance::updateOrCreate(
            [
                'lesson_id' => $lesson->id,
                'child_id'  => $data['child_id'],
            ],
            [
                'status'   => $data['status'],
                'notes'    => $data['notes'] ?? null,
                'approved' => $existing ? $existing->approved : false, // preserve approval if somehow already set
                'date'      => $date,
            ]
        );

        // Detect duplicate attendance rows for the same lesson/child/date
        $duplicates = Attendance::where('lesson_id', $lesson->id)
            ->where('child_id', $data['child_id'])
            ->whereDate('date', $date)
            ->get();

        if ($duplicates->count() > 1) {
            $dupIds = $duplicates->pluck('id')->all();
            // Inform the user/admin that duplicates were detected.
            // We still keep the created/updated row, but surface a warning so the admin can inspect.
            return redirect()->back()
                ->with('warning', 'Attendance saved, but duplicate attendance rows detected for this pupil/date. Duplicate IDs: ' . implode(',', $dupIds));
        }

        // now check if attendance is stored or not
       

        return redirect()->back()
                     ->with('success', 'Attendance saved – awaiting approval');
    }

    /* ───────────────── admin approves / overrides a single row ───────────────── */

    public function approve(Request $request, Attendance $attendance)
    {
        $request->validate([
            'status'  => 'required|in:present,absent,late,excused',
            'approve' => 'required|boolean',
        ]);

        $attendance->update([
            'status'      => $request->status,
            'approved'    => $request->approve,
            'approved_by' => $request->approve ? $request->user()->id : null,
            'approved_at' => $request->approve ? now() : null,
        ]);

        return back()->with('success', 'Attendance updated');
    }

    /* ───────────────── overview page ───────────────── */

    /**
     * Teacher's attendance overview - shows only their lessons
     */
    public function teacherOverview()
    {
        $user = \Illuminate\Support\Facades\Auth::user();
        
        // Get lessons for this teacher's organization or assigned to them
        $lessons = Lesson::with([
                'attendances:id,lesson_id,status,date',
            ])
            ->when($user->current_organization_id, function($query) use ($user) {
                $query->where('organization_id', $user->current_organization_id);
            })
            ->latest('start_time')
            ->get(['id', 'title', 'start_time'])
            ->map(function (Lesson $lesson) {
                $lessonDate = $lesson->start_time?->toDateString();

                // Determine allowed child IDs via Access table (paid access)
                $accessRows = \App\Models\Access::where('access', true)
                    ->where('payment_status', 'paid')
                    ->where(function ($q) use ($lesson) {
                        $q->where('lesson_id', $lesson->id)
                          ->orWhereJsonContains('lesson_ids', $lesson->id)
                          ->orWhereRaw('JSON_CONTAINS(lesson_ids, ?)', [json_encode((string) $lesson->id)]);
                    })
                    ->get();

                $childIds = $accessRows->pluck('child_id')->unique()->values();
                $childrenCount = $childIds->count();

                // attendance rows only for that date
                $rowsToday = $lessonDate
                    ? $lesson->attendances->where('date', $lessonDate)
                    : collect();

                return [
                    'id'               => $lesson->id,
                    'title'            => $lesson->title,
                    'start_time'       => $lesson->start_time,
                    'children_count'   => $childrenCount,
                    'attendances_count'=> $rowsToday->count(),
                    'present_count'    => $rowsToday->where('status', 'present')->count(),
                ];
            });

        return Inertia::render('@admin/Attendance/Overview', [
            'lessons' => $lessons,
        ]);
    }

    /* ───────── overview page ───────── */
public function overview()
{
    // 1️⃣  eager-load attendances only (we will derive allowed pupils from Access)
    $lessons = Lesson::with([
            // all attendance rows so we can filter by date in PHP
            'attendances:id,lesson_id,status,date',
        ])
        ->latest('start_time')
        ->get(['id', 'title', 'start_time'])

        // 2️⃣  transform → the slim array the Vue page expects
        ->map(function (Lesson $lesson) {

            $lessonDate = $lesson->start_time?->toDateString();

            // 2a) Determine allowed child IDs via Access table (paid access)
            $accessRows = \App\Models\Access::where('access', true)
                ->where('payment_status', 'paid')
                ->where(function ($q) use ($lesson) {
                    $q->where('lesson_id', $lesson->id)
                      ->orWhereJsonContains('lesson_ids', $lesson->id)
                      ->orWhereRaw('JSON_CONTAINS(lesson_ids, ?)', [json_encode((string) $lesson->id)]);
                })
                ->get();

         

            $childIds = $accessRows->pluck('child_id')->unique()->values();
            $childrenCount = $childIds->count();

            // attendance rows only for that date
            $rowsToday = $lessonDate
                ? $lesson->attendances->where('date', $lessonDate)
                : collect();

            // NOTE: attendances may contain rows for children who do not have Access;
            // the UI expects counts based on actual allowed children (Access), so we
            // compute children_count from Access while counts of saved rows still
            // come from attendance rows.
            return [
                'id'               => $lesson->id,
                'title'            => $lesson->title,
                'start_time'       => $lesson->start_time,         // for display
                'children_count'   => $childrenCount,

                // how many rows total for that date
                'attendances_count'=> $rowsToday->count(),

                // how many “present” rows for that date
                'present_count'    => $rowsToday
                                        ->where('status', 'present')
                                        ->count(),
            ];
        });

    return Inertia::render('@admin/Attendance/Overview', [
        'lessons' => $lessons,
    ]);
}



    /* ───────────────── per-lesson sheet ───────────────── */

    public function sheet(Lesson $lesson)
    {
        // ensure attendances for the lesson date are loaded (used elsewhere)
        $lesson->load([
            'attendances' => fn ($q) =>
                $q->whereDate('date', $this->lessonDate($lesson)),
        ]);

        $lessonDate = $this->lessonDate($lesson);

        // Determine allowed child IDs via Access table (paid access)
        $accessRows = \App\Models\Access::where('access', true)
            ->where('payment_status', 'paid')
            ->where(function ($q) use ($lesson) {
                $q->where('lesson_id', $lesson->id)
                  ->orWhereJsonContains('lesson_ids', $lesson->id)
                  ->orWhereRaw('JSON_CONTAINS(lesson_ids, ?)', [json_encode((string) $lesson->id)]);
            })
            ->get();

       

        $childIds = $accessRows->pluck('child_id')->unique()->values();

        // Load Child models for the allowed child IDs (keep payload small)
        $children = \App\Models\Child::whereIn('id', $childIds->all())
            ->get(['id', 'child_name']);

        // Explicitly fetch attendance rows from the Attendance table for the lesson/date
        // (this guarantees we read the DB directly and not rely on a potentially stale relation)
        $attendanceRows = \App\Models\Attendance::where('lesson_id', $lesson->id)
            // ->whereDate('date', $lessonDate)
            ->get(['id', 'child_id', 'status', 'approved', 'notes', 'date']);
        
            // Build rows for the sheet from Access-backed children (ensures we only show allowed pupils)
        $rows = $children->map(function ($child) use ($attendanceRows) {
            $a = $attendanceRows->firstWhere('child_id', $child->id);

            return [
                'child_id'      => $child->id,
                'name'          => $child->child_name,
                'status'        => $a->status   ?? 'pending',
                'approved'      => $a->approved ?? false,
                'notes'         => $a->notes    ?? '',
                'attendance_id' => $a->id       ?? null,
            ];
        });

       
        return Inertia::render('@admin/Attendance/Sheet', [
            'lesson' => $lesson->only('id', 'title', 'start_time'),
            'rows'   => $rows,
        ]);
    }

    /* ───────────────── bulk mark all ───────────────── */

    public function markAll(Request $request, Lesson $lesson)
    {
        $data = $request->validate([
            'status' => 'required|in:present,absent,late,excused',
            'notes'  => 'nullable|string|max:500',
        ]);

        $date = $this->lessonDate($lesson);
        $skipped = [];
        $updated = [];
        $duplicatesFound = [];

        // Determine allowed child IDs via Access table (paid access) — consistent with sheet/overview
        $accessRows = \App\Models\Access::where('access', true)
            ->where('payment_status', 'paid')
            ->where(function ($q) use ($lesson) {
                $q->where('lesson_id', $lesson->id)
                  ->orWhereJsonContains('lesson_ids', $lesson->id)
                  ->orWhereRaw('JSON_CONTAINS(lesson_ids, ?)', [json_encode((string) $lesson->id)]);
            })
            ->get();

        $childIds = $accessRows->pluck('child_id')->unique()->values();

       
        // Load child models for nicer logs (optional)
        $children = \App\Models\Child::whereIn('id', $childIds->all())->get(['id','child_name']);

        foreach ($children as $child) {
            $existing = Attendance::where('lesson_id', $lesson->id)
                ->where('child_id', $child->id)
                ->whereDate('date', $date)
                ->get();

            // If multiple existing rows, mark as duplicates and log
            if ($existing->count() > 1) {
                $dupIds = $existing->pluck('id')->all();
                $duplicatesFound[] = [
                    'child_id' => $child->id,
                    'duplicate_ids' => $dupIds,
                ];
               
            }

            $single = $existing->first();

            if ($single && $single->approved) {
                // skip updating approved rows
                $skipped[] = $child->id;
               
                continue;
            }

            $row = Attendance::updateOrCreate(
                ['lesson_id' => $lesson->id, 'child_id' => $child->id, 'date' => $date],
                ['status' => $data['status'], 'notes' => $data['notes'] ?? null, 'approved' => $single ? $single->approved : false]
            );

            // After update, check if duplicates were created/left
            $post = Attendance::where('lesson_id', $lesson->id)
                ->where('child_id', $child->id)
                ->whereDate('date', $date)
                ->get();

            if ($post->count() > 1) {
                $dupIds = $post->pluck('id')->all();
                $duplicatesFound[] = [
                    'child_id' => $child->id,
                    'duplicate_ids' => $dupIds,
                ];
               
            }

            $updated[] = $child->id;
        }

       

        $message = 'All students marked “' . $data['status'] . '”';
        if (!empty($skipped)) {
            $message .= ' — skipped ' . count($skipped) . ' approved rows';
        }
        if (!empty($duplicatesFound)) {
            $dupSummary = array_map(fn($d) => $d['child_id'] . ':' . implode('|', $d['duplicate_ids']), $duplicatesFound);
            $message .= ' — duplicates detected: ' . implode(', ', $dupSummary);
            // Also flash a warning for admin visibility
            return back()->with('warning', $message);
        }

        return back()->with('success', $message);
    }

    /* ───────────────── bulk approve all ───────────────── */

    public function approveAll(Request $request, Lesson $lesson)
    {
       
        Attendance::where('lesson_id', $lesson->id)
                //   ->whereDate('date', $this->lessonDate($lesson))
                  ->update([
                      'approved'    => true,
                      'approved_by' => $request->user()->id,
                      'approved_at' => now(),
                  ]);
                
        return back()->with('success', 'Attendance approved');
    }
}
