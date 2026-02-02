<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Task;
use Inertia\Inertia;

class TaskController extends Controller
{
    public function index()
    {
        $tasks = Task::all();
        return Inertia::render('@admin/Tasks/Index', ['tasks' => $tasks]);
    }

    public function create()
    {
        return Inertia::render('@admin/Tasks/Create');
    }

    public function store(Request $request)
    {
        $validatedData = $request->validate([
            'title'       => 'required|string|max:255',
            'description' => 'nullable|string',
            'due_date'    => 'required|date',
            'priority'    => 'required|in:low,medium,high',
            'status'      => 'required|in:pending,completed,overdue',
        ]);

        // Set default user values.
        $validatedData['assigned_to'] = 1;
        $validatedData['created_by'] = 1;

        $task = Task::create($validatedData);

        return redirect()->route('tasks.index')->with('success', 'Task created successfully!');
    }

    public function show($id)
    {
        $task = Task::with('completions')->findOrFail($id);
        return Inertia::render('@admin/Tasks/Show', ['task' => $task]);
    }

    public function edit($id)
    {
        $task = Task::findOrFail($id);
        return Inertia::render('@admin/Tasks/Edit', ['task' => $task]);
    }

    public function update(Request $request, $id)
    {
        $task = Task::findOrFail($id);
        $validatedData = $request->validate([
            'title'       => 'required|string|max:255',
            'description' => 'nullable|string',
            'due_date'    => 'required|date',
            'priority'    => 'required|in:low,medium,high',
            'status'      => 'required|in:pending,completed,overdue',
        ]);

        $task->update($validatedData);

        return redirect()->route('tasks.show', $task->id)->with('success', 'Task updated successfully!');
    }

    public function destroy($id)
    {
        $task = Task::findOrFail($id);
        $task->delete();
        return redirect()->route('tasks.index')->with('success', 'Task deleted successfully!');
    }
}
