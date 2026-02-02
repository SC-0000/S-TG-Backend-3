<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Child;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class TeacherStudentAssignmentController extends Controller
{
    /**
     * Display the assignment management page
     */
    public function index()
    {
        return Inertia::render('@admin/TeacherStudentAssignments/Index');
    }

    /**
     * Get filtered teachers and students data
     */
    public function getData(Request $request)
    {
        $orgId = auth()->user()->current_organization_id;
        
        // Teachers query with filters
        $teachers = User::where('role', 'teacher')
            ->where('current_organization_id', $orgId)
            ->when($request->teacher_search, function ($q) use ($request) {
                $q->where('name', 'like', "%{$request->teacher_search}%");
            })
            ->withCount('assignedStudents')
            ->get()
            ->map(function ($teacher) {
                return [
                    'id' => $teacher->id,
                    'name' => $teacher->name,
                    'email' => $teacher->email,
                    'assigned_students_count' => $teacher->assigned_students_count,
                ];
            });
        
        // Students query with comprehensive filters
        $studentsQuery = Child::where('organization_id', $orgId)
            ->with(['user', 'assignedTeachers']);
        
        // Search filter
        if ($request->student_search) {
            $studentsQuery->where(function ($q) use ($request) {
                $q->where('child_name', 'like', "%{$request->student_search}%")
                  ->orWhereHas('user', function ($u) use ($request) {
                      $u->where('name', 'like', "%{$request->student_search}%")
                        ->orWhere('email', 'like', "%{$request->student_search}%");
                  });
            });
        }
        
        // Year group filter
        if ($request->year_group) {
            $studentsQuery->where('year_group', $request->year_group);
        }
        
        // Area filter
        if ($request->area) {
            $studentsQuery->where('area', $request->area);
        }
        
        // School filter
        if ($request->school) {
            $studentsQuery->where('school_name', 'like', "%{$request->school}%");
        }
        
        // Unassigned only filter
        if ($request->unassigned_only) {
            $studentsQuery->doesntHave('assignedTeachers');
        }
        
        $students = $studentsQuery->paginate(50);
        
        return response()->json([
            'teachers' => $teachers,
            'students' => $students,
        ]);
    }

    /**
     * Assign multiple students to a single teacher
     */
    public function assign(Request $request)
    {
        $validated = $request->validate([
            'teacher_id' => 'required|exists:users,id',
            'student_ids' => 'required|array',
            'student_ids.*' => 'exists:children,id',
            'notes' => 'nullable|string',
        ]);
        
        $teacher = User::findOrFail($validated['teacher_id']);
        $orgId = auth()->user()->current_organization_id;
        
        foreach ($validated['student_ids'] as $studentId) {
            $teacher->assignedStudents()->syncWithoutDetaching([
                $studentId => [
                    'assigned_by' => auth()->id(),
                    'assigned_at' => now(),
                    'notes' => $validated['notes'] ?? null,
                    'organization_id' => $orgId,
                ]
            ]);
            
            // ✅ Create task for teacher for each assigned student
            $student = Child::find($studentId);
            
            $notes = isset($validated['notes']) && !empty($validated['notes']) ? $validated['notes'] : null;
            
            \App\Models\AdminTask::create([
                'task_type'      => 'New Student Assigned',
                'assigned_to'    => $teacher->id, // Specific teacher
                'status'         => 'Pending',
                'related_entity' => route('teacher.students.show', $student->id),
                'priority'       => 'Medium',
                'description'    => "Student '{$student->child_name}' (Parent: {$student->user->name}) has been assigned to you by " . 
                                    auth()->user()->name . 
                                    ($notes ? ". Notes: {$notes}" : "."),
            ]);
            
            \Illuminate\Support\Facades\Log::info('[TeacherStudentAssignmentController] Task created for teacher', [
                'teacher_id' => $teacher->id,
                'student_id' => $studentId,
                'student_name' => $student->child_name
            ]);
        }
        
        return response()->json([
            'success' => true,
            'message' => count($validated['student_ids']) . ' student(s) assigned to ' . $teacher->name . ' successfully!'
        ]);
    }

    /**
     * Bulk assign: multiple students to multiple teachers
     */
    public function bulkAssign(Request $request)
    {
        $validated = $request->validate([
            'teacher_ids' => 'required|array',
            'teacher_ids.*' => 'exists:users,id',
            'student_ids' => 'required|array',
            'student_ids.*' => 'exists:children,id',
            'notes' => 'nullable|string',
        ]);
        
        $orgId = auth()->user()->current_organization_id;
        $assignedCount = 0;
        
        foreach ($validated['teacher_ids'] as $teacherId) {
            $teacher = User::findOrFail($teacherId);
            
            foreach ($validated['student_ids'] as $studentId) {
                $teacher->assignedStudents()->syncWithoutDetaching([
                    $studentId => [
                        'assigned_by' => auth()->id(),
                        'assigned_at' => now(),
                        'notes' => $validated['notes'] ?? null,
                        'organization_id' => $orgId,
                    ]
                ]);
                $assignedCount++;
                
                // ✅ Create task for teacher for each assigned student
                $student = Child::find($studentId);
                
                $notes = isset($validated['notes']) && !empty($validated['notes']) ? $validated['notes'] : null;
                
                \App\Models\AdminTask::create([
                    'task_type'      => 'New Student Assigned',
                    'assigned_to'    => $teacher->id, // Specific teacher
                    'status'         => 'Pending',
                    'related_entity' => route('teacher.students.show', $student->id),
                    'priority'       => 'Medium',
                    'description'    => "Student '{$student->child_name}' (Parent: {$student->user->name}) has been assigned to you by " . 
                                        auth()->user()->name . 
                                        ($notes ? ". Notes: {$notes}" : "."),
                ]);
                
                \Illuminate\Support\Facades\Log::info('[TeacherStudentAssignmentController] Bulk task created for teacher', [
                    'teacher_id' => $teacher->id,
                    'student_id' => $studentId
                ]);
            }
        }
        
        return response()->json([
            'success' => true,
            'message' => "Bulk assignment completed! {$assignedCount} assignment(s) created."
        ]);
    }

    /**
     * Remove a teacher-student assignment
     */
    public function destroy($assignmentId)
    {
        // Find the pivot record
        $assignment = DB::table('child_teacher')->where('id', $assignmentId)->first();
        
        if (!$assignment) {
            return response()->json([
                'success' => false,
                'message' => 'Assignment not found.'
            ], 404);
        }
        
        // Delete the assignment
        DB::table('child_teacher')->where('id', $assignmentId)->delete();
        
        return response()->json([
            'success' => true,
            'message' => 'Assignment removed successfully!'
        ]);
    }

    /**
     * Unassign a student from a teacher
     */
    public function unassign(Request $request)
    {
        $validated = $request->validate([
            'teacher_id' => 'required|exists:users,id',
            'student_id' => 'required|exists:children,id',
        ]);
        
        $teacher = User::findOrFail($validated['teacher_id']);
        $teacher->assignedStudents()->detach($validated['student_id']);
        
        return response()->json([
            'success' => true,
            'message' => 'Student unassigned from teacher successfully!'
        ]);
    }

    /**
     * Get all current assignments for display
     */
    public function getAssignments(Request $request)
    {
        $orgId = auth()->user()->current_organization_id;
        
        $assignments = DB::table('child_teacher')
            ->join('children', 'child_teacher.child_id', '=', 'children.id')
            ->join('users as teachers', 'child_teacher.teacher_id', '=', 'teachers.id')
            ->join('users as students_parents', 'children.user_id', '=', 'students_parents.id')
            ->join('users as assigned_by_users', 'child_teacher.assigned_by', '=', 'assigned_by_users.id')
            ->where('child_teacher.organization_id', $orgId)
            ->select(
                'child_teacher.id as assignment_id',
                'children.id as child_id',
                'children.child_name',
                'teachers.id as teacher_id',
                'teachers.name as teacher_name',
                'students_parents.name as parent_name',
                'students_parents.email as parent_email',
                'assigned_by_users.name as assigned_by_name',
                'child_teacher.assigned_at',
                'child_teacher.notes'
            )
            ->orderBy('child_teacher.assigned_at', 'desc')
            ->paginate(50);
        
        return response()->json($assignments);
    }
}
