<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\HomeworkAssignment;
use Inertia\Inertia;

class HomeworkController extends Controller
{
    public function index()
    {
        $assignments = HomeworkAssignment::all();
        return Inertia::render('@admin/Homework/Index', ['assignments' => $assignments]);
    }

    public function create()
    {
        return Inertia::render('@admin/Homework/Create');
    }

    public function store(Request $request)
    {
        $validatedData = $request->validate([
            'title'       => 'required|string|max:255',
            'description' => 'required|string',
            'subject'     => 'required|string',
            'due_date'    => 'required|date',
            // attachments is optional; we'll handle file uploads below
        ]);

        // Process attachments (multiple file upload)
        $attachments = [];
        if ($request->hasFile('attachments')) {
            // Expecting multiple files. Ensure your file input name is attachments[]
            foreach ($request->file('attachments') as $file) {
                $path = $file->store('homework_attachments', 'public');
                $attachments[] = $path;
            }
        }
        $validatedData['attachments'] = $attachments;
        // Set default created_by
        $validatedData['created_by'] = 1;

        $assignment = HomeworkAssignment::create($validatedData);

        return redirect()->route('homework.show', $assignment->id)
                         ->with('success', 'Homework assignment created successfully!');
    }

    public function show($id)
    {
        $assignment = HomeworkAssignment::findOrFail($id);
        return Inertia::render('@admin/Homework/Show', ['assignment' => $assignment]);
    }

    public function edit($id)
    {
        $assignment = HomeworkAssignment::findOrFail($id);
        return Inertia::render('@admin/Homework/Edit', ['assignment' => $assignment]);
    }

    public function update(Request $request, $id)
    {
        $assignment = HomeworkAssignment::findOrFail($id);
        $validatedData = $request->validate([
            'title'       => 'required|string|max:255',
            'description' => 'required|string',
            'subject'     => 'required|string',
            'due_date'    => 'required|date',
        ]);

        // Process attachments (if any new files are uploaded, append to existing attachments)
        $existingAttachments = $assignment->attachments ?? [];
        if ($request->hasFile('attachments')) {
            foreach ($request->file('attachments') as $file) {
                $path = $file->store('homework_attachments', 'public');
                $existingAttachments[] = $path;
            }
        }
        $validatedData['attachments'] = $existingAttachments;

        $assignment->update($validatedData);

        return redirect()->route('homework.show', $assignment->id)
                         ->with('success', 'Homework assignment updated successfully!');
    }

    public function destroy($id)
    {
        $assignment = HomeworkAssignment::findOrFail($id);
        $assignment->delete();
        return redirect()->route('homework.index')
                         ->with('success', 'Homework assignment deleted successfully!');
    }
}
