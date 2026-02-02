<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\HomeworkSubmission;
use App\Models\HomeworkAssignment;
use Inertia\Inertia;

class HomeworkSubmissionController extends Controller
{
    public function index()
    {
        $submissions = HomeworkSubmission::all();
        return Inertia::render('@admin/HomeworkSubmissions/Index', ['submissions' => $submissions]);
    }

    // For a student to submit an assignment.
    public function create($assignmentId)
    {
        // Optionally, load the assignment details.
        $assignment = HomeworkAssignment::findOrFail($assignmentId);
        return Inertia::render('@admin/HomeworkSubmissions/Create', ['assignment' => $assignment]);
    }

    public function store(Request $request, $assignmentId)
    {
        $validatedData = $request->validate([
            'submission_status' => 'required|in:draft,submitted,graded',
            'content'           => 'nullable|string',
            // attachments: handle file uploads.
        ]);

        // Process attachments (multiple file upload)
        $attachments = [];
        if ($request->hasFile('attachments')) {
            foreach ($request->file('attachments') as $file) {
                $path = $file->store('homework_submissions', 'public');
                $attachments[] = $path;
            }
        }
        $validatedData['attachments'] = $attachments;
        // Set foreign keys default since we don't have auth.
        $validatedData['assignment_id'] = $assignmentId;
        $validatedData['student_id'] = 1; // default student id

        $submission = HomeworkSubmission::create($validatedData);

        return redirect()->route('homework.submission.show', $submission->id)
                         ->with('success', 'Homework submission created successfully!');
    }

    public function show($id)
    {
        $submission = HomeworkSubmission::findOrFail($id);
        return Inertia::render('@admin/HomeworkSubmissions/Show', ['submission' => $submission]);
    }

    public function edit($id)
    {
        $submission = HomeworkSubmission::findOrFail($id);
        return Inertia::render('@admin/HomeworkSubmissions/Edit', ['submission' => $submission]);
    }

    public function update(Request $request, $id)
    {
        $submission = HomeworkSubmission::findOrFail($id);
        $validatedData = $request->validate([
            'submission_status' => 'required|in:draft,submitted,graded',
            'content'           => 'nullable|string',
        ]);

        // Process new attachments if provided and merge with existing.
        $existingAttachments = $submission->attachments ?? [];
        if ($request->hasFile('attachments')) {
            foreach ($request->file('attachments') as $file) {
                $path = $file->store('homework_submissions', 'public');
                $existingAttachments[] = $path;
            }
        }
        $validatedData['attachments'] = $existingAttachments;

        $submission->update($validatedData);

        return redirect()->route('homework.submission.show', $submission->id)
                         ->with('success', 'Homework submission updated successfully!');
    }

    public function destroy($id)
    {
        $submission = HomeworkSubmission::findOrFail($id);
        $submission->delete();
        return redirect()->route('homework.submissions.index')
                         ->with('success', 'Homework submission deleted successfully!');
    }
}
