<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\AdminTask;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;

class AdminTaskController extends Controller
{
    public function index()
    {
        $orgId = Auth::user()?->current_organization_id;
        $userId = Auth::id();
        $tasks = AdminTask::with('admin')
            ->when($orgId, fn($q) => $q->forOrganization($orgId))
            ->where(function ($query) use ($userId) {
                $query->whereNull('assigned_to')
                      ->orWhere('assigned_to', $userId);
            })
            ->get();
        return Inertia::render('@admin/AdminTasks/Index', ['tasks' => $tasks]);
    }

    public function create()
    {
        $teachers = \App\Models\User::where('role', 'teacher')->select('id', 'name', 'email')->get();
        
        return Inertia::render('@admin/AdminTasks/Create', [
            'teachers' => $teachers
        ]);
    }

    public function store(Request $request)
    {
        $validatedData = $request->validate([
            'task_type'   => 'required|string|max:255',
            'assigned_to' => 'required|integer',
            'status'      => 'required|in:Pending,In Progress,Completed',
            'related_entity' => 'nullable|string',
            'priority'    => 'required|in:Low,Medium,High,Critical',
        ]);

        $validatedData['organization_id'] = $request->user()?->current_organization_id;

        AdminTask::create($validatedData);

        return redirect()->route('admin_tasks.index')->with('success', 'Task created successfully!');
    }

    public function show($id)
    {
        $orgId = Auth::user()?->current_organization_id;
        $task = AdminTask::with('admin')
            ->when($orgId, fn($q) => $q->forOrganization($orgId))
            ->findOrFail($id);
        return Inertia::render('@admin/AdminTasks/Show', ['task' => $task]);
    }

    public function edit($id)
    {
        $orgId = Auth::user()?->current_organization_id;
        $task = AdminTask::when($orgId, fn($q) => $q->forOrganization($orgId))->findOrFail($id);
        $teachers = \App\Models\User::where('role', 'teacher')->select('id', 'name', 'email')->get();
        
        return Inertia::render('@admin/AdminTasks/Edit', [
            'task' => $task,
            'teachers' => $teachers
        ]);
    }

    public function update(Request $request, $id)
    {
        $orgId = Auth::user()?->current_organization_id;
        $task = AdminTask::when($orgId, fn($q) => $q->forOrganization($orgId))->findOrFail($id);

        $validatedData = $request->validate([
            'task_type'   => 'sometimes|required|string|max:255',
            'assigned_to' => 'nullable|integer|exists:users,id',
            'status'      => 'sometimes|required|in:Pending,In Progress,Completed',
            'related_entity' => 'nullable|string',
            'priority'    => 'sometimes|required|in:Low,Medium,High,Critical',
        ]);
         $validatedData['assigned_to'] = Auth::id();
        $task->update($validatedData);

        return redirect()->route('admin_tasks.index')->with('success', 'Task updated successfully!');
    }

    public function destroy($id)
    {
        $orgId = Auth::user()?->current_organization_id;
        $task = AdminTask::when($orgId, fn($q) => $q->forOrganization($orgId))->findOrFail($id);
        $task->delete();

        return redirect()->route('admin_tasks.index')->with('success', 'Task deleted successfully!');
    }

    /**
     * Teacher-specific methods
     */

    // Get tasks for current teacher
    public function teacherIndex(Request $request)
    {
        $teacherId = Auth::id();
        $orgId = Auth::user()?->current_organization_id;
        
        $tasks = AdminTask::when($orgId, fn($q) => $q->forOrganization($orgId))
        ->where('task_type', '!=', 'teacher_approval')
        ->where(function ($query) use ($teacherId) {
            $query->whereNull('assigned_to')
                  ->orWhere('assigned_to', $teacherId);
        })
        ->with('assignedUser')
        ->orderBy('created_at', 'desc')
        ->paginate(20);
        
        return Inertia::render('@admin/Teacher/Tasks/Index', [
            'tasks' => $tasks,
        ]);
    }

    // Show task details for teacher
    public function teacherShow($id)
    {
        $teacherId = Auth::id();
        $orgId = Auth::user()?->current_organization_id;
        
        $task = AdminTask::when($orgId, fn($q) => $q->forOrganization($orgId))
        ->where('task_type', '!=', 'teacher_approval')
        ->where(function ($query) use ($teacherId) {
            $query->whereNull('assigned_to')
                  ->orWhere('assigned_to', $teacherId);
        })
        ->with('assignedUser')
        ->findOrFail($id);
        
        return Inertia::render('@admin/Teacher/Tasks/Show', [
            'task' => $task,
        ]);
    }

    // Update task status (teacher can mark as completed)
    public function updateStatus(Request $request, $id)
    {
        $teacherId = Auth::id();
        $orgId = Auth::user()?->current_organization_id;
        
        $task = AdminTask::when($orgId, fn($q) => $q->forOrganization($orgId))
        ->where('task_type', '!=', 'teacher_approval')
        ->where(function ($query) use ($teacherId) {
            $query->whereNull('assigned_to')
                  ->orWhere('assigned_to', $teacherId);
        })->findOrFail($id);

        $validatedData = $request->validate([
            'status' => 'required|in:Pending,In Progress,Completed',
        ]);

        $task->update($validatedData);

        // Update completed_at if status is Completed
        if ($validatedData['status'] === 'Completed' && !$task->completed_at) {
            $task->update(['completed_at' => now()]);
        }

        return redirect()->route('teacher.tasks.index')->with('success', 'Task status updated successfully!');
    }

    // Get count of pending tasks for real-time counter
    public function getPendingCount()
    {
        $teacherId = Auth::id();
        $orgId = Auth::user()?->current_organization_id;
        
        $count = AdminTask::when($orgId, fn($q) => $q->forOrganization($orgId))
        ->where('task_type', '!=', 'teacher_approval')
        ->where(function ($query) use ($teacherId) {
            $query->whereNull('assigned_to')
                  ->orWhere('assigned_to', $teacherId);
        })
        ->where('status', 'Pending')
        ->count();
        
        return response()->json(['count' => $count]);
    }
}
