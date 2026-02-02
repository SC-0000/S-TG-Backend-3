<?php 
// app/Http/Controllers/Admin/TeacherController.php
namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Teacher;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;

class TeacherController extends Controller
{
   public function index(Request $request)
{
    $user = auth()->user();

    $teachers = User::query()
        ->where('role', 'teacher')
        ->when($user->role !== 'super_admin' && $user->current_organization_id, fn($q) =>
            $q->where('current_organization_id', $user->current_organization_id)
        )
        ->orderBy('name')
        ->paginate(20);

    return Inertia::render('@admin/Teacher/Index', [
        'teachers' => $teachers,
    ]);
}

    /**
     * Show all teachers with their assigned students
     */
    public function assignments(Request $request)
    {
        $user = auth()->user();

        $teachers = User::where('role', 'teacher')
            ->when(
                $user->role !== 'super_admin' && $user->current_organization_id,
                fn ($q) => $q->where('current_organization_id', $user->current_organization_id)
            )
            ->with(['assignedStudents.user'])
            ->orderBy('name')
            ->get()
            ->map(function ($teacher) {
                return [
                    'id' => $teacher->id,
                    'name' => $teacher->name,
                    'email' => $teacher->email,
                    'assigned_students' => $teacher->assignedStudents->map(function ($student) {
                        return [
                            'id' => $student->id,
                            'name' => $student->child_name,
                            'parent_name' => $student->user->name ?? null,
                            'year_group' => $student->year_group,
                            'notes' => $student->pivot->notes,
                            'assigned_at' => $student->pivot->assigned_at,
                        ];
                    }),
                ];
            });

        return Inertia::render('@admin/Teacher/Assignments', [
            'teachers' => $teachers,
        ]);
    }


    public function create()
    {
        $users = User::select('id','name')->orderBy('name')->get();
        return Inertia::render('@admin/Teacher/Create', ['users' => $users]);
    }

    public function store(Request $request)
    {
        Log::info('Teacher store request', $request->all());
        $data = $request->validate([
            'user_id'     => 'nullable|exists:users,id',
            'name'        => 'required|string|max:255',
            'title'       => 'required|string|max:255',
            'role'        => 'nullable|string|max:255',
            'bio'         => 'required|string',
            'category'    => 'nullable|string|max:100',
            'metadata'    => 'nullable|array',
            'metadata.phone'   => 'nullable|string',
            'metadata.email'   => 'nullable|email',
            'metadata.address' => 'nullable|string',
            'specialties'      => 'nullable|array',
            'image'       => 'nullable|image|max:2048',
        ]);

        // handle image
        if ($request->hasFile('image')) {
            $data['image_path'] = $request->file('image')->store('teachers','public');
        }

        // Ensure specialties is always an array
        $data['specialties'] = isset($data['specialties']) && is_array($data['specialties'])
          ? array_map('trim', $data['specialties'])
          : [];
        
        // Ensure metadata is always an array
        $data['metadata'] = $data['metadata'] ?? [];

        Teacher::create($data);

        return redirect()->route('teachers.index')
                         ->with('success','Teacher created.');
    }

    public function show($teacherId)
    {
        $authUser = auth()->user();

        // Load teacher user with assigned students (scoped by org for non-super-admins)
        $teacherUser = User::with(['assignedStudents.user'])
            ->where('role', 'teacher')
            ->when(
                $authUser->role !== 'super_admin' && $authUser->current_organization_id,
                fn ($q) => $q->where('current_organization_id', $authUser->current_organization_id)
            )
            ->findOrFail($teacherId);

        // Optional profile record from Teacher table (if present)
        $profile = Teacher::where('user_id', $teacherUser->id)->first();

        $childIds = $teacherUser->assignedStudents->pluck('id');
        $transactionIds = \App\Models\Access::whereIn('child_id', $childIds)
            ->whereNotNull('transaction_id')
            ->pluck('transaction_id')
            ->unique();

        $transactions = \App\Models\Transaction::with('user')
            ->whereIn('id', $transactionIds)
            ->where('status', 'completed')
            ->latest()
            ->get();

        $teacher = [
            'id' => $teacherUser->id,
            'name' => $teacherUser->name,
            'email' => $teacherUser->email,
            'role' => $teacherUser->role,
            'profile' => [
                'title' => $profile->title ?? null,
                'bio' => $profile->bio ?? null,
                'category' => $profile->category ?? null,
                'specialties' => $profile->specialties ?? [],
                'metadata' => $profile->metadata ?? [],
                'image_path' => $profile->image_path ?? null,
            ],
            'assigned_students' => $teacherUser->assignedStudents->map(function ($student) {
                return [
                    'id' => $student->id,
                    'name' => $student->child_name,
                    'year_group' => $student->year_group,
                    'parent_name' => $student->user->name ?? null,
                    'notes' => $student->pivot->notes,
                    'assigned_at' => optional($student->pivot->assigned_at)->toDateTimeString(),
                ];
            }),
            'transactions' => $transactions->map(function ($tx) {
                return [
                    'id' => $tx->id,
                    'user_name' => $tx->user?->name,
                    'user_email' => $tx->user_email,
                    'total' => $tx->total,
                    'status' => $tx->status,
                    'created_at' => $tx->created_at,
                ];
            }),
            'revenue' => $transactions->sum('total'),
        ];

        return Inertia::render('@admin/Teacher/Show', [
            'teacher' => $teacher,
        ]);
    }

    public function edit(Teacher $teacher)
    {
        $users = User::select('id','name')->orderBy('name')->get();
        return Inertia::render('@admin/Teacher/Edit', [
            'teacher' => $teacher,
            'users'   => $users,
        ]);
    }

    public function update(Request $request, Teacher $teacher)
    {
        Log::info('Teacher update request', $request->all());
        $data = $request->validate([
            'user_id'     => 'nullable|exists:users,id',
            'name'        => 'required|string|max:255',
            'title'       => 'required|string|max:255',
            'role'        => 'nullable|string|max:255',
            'bio'         => 'required|string',
            'category'    => 'nullable|string|max:100',
            'metadata'    => 'nullable|array',
            'metadata.phone'   => 'nullable|string',
            'metadata.email'   => 'nullable|email',
            'metadata.address' => 'nullable|string',
            'specialties'      => 'nullable|array',
            'image'       => 'nullable|image|max:2048',
        ]);

        if ($request->hasFile('image')) {
            // delete old
            if ($teacher->image_path) {
                Storage::disk('public')->delete($teacher->image_path);
            }
            $data['image_path'] = $request->file('image')->store('teachers','public');
        }

        $data['specialties'] = isset($data['specialties']) && is_array($data['specialties'])
          ? array_map('trim', $data['specialties'])
          : [];
        
        // Ensure metadata is always an array
        $data['metadata'] = $data['metadata'] ?? [];

        $teacher->update($data);

        return redirect()->route('teachers.index')
                         ->with('success','Teacher updated.');
    }

    public function destroy(Teacher $teacher)
    {
        if ($teacher->image_path) {
            Storage::disk('public')->delete($teacher->image_path);
        }
        $teacher->delete();

        return redirect()->route('teachers.index')
                         ->with('success','Teacher deleted.');
    }
}
