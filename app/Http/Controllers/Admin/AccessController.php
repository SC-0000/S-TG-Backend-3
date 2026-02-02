<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Access;
use App\Models\Child;
use App\Models\Lesson;
use App\Models\Assessment;
use App\Models\Invoice;
use Illuminate\Http\Request;
use Inertia\Inertia;

class AccessController extends Controller
{
    // Show all access records
    public function index()
    {
        $accesses = Access::with([
                'child.user', // eager load parent user via child
                'lesson',
                'assessment',
            ])
            ->orderByDesc('created_at')
            ->get();

        $lessons = \App\Models\Lesson::select('id', 'title')->orderBy('title')->get();
        $assessments = \App\Models\Assessment::select('id', 'title')->orderBy('title')->get();
        $users = \App\Models\User::with('children')->where('role', 'parent')->get();

        return Inertia::render('@admin/access/Index', [
            'accesses' => $accesses,
            'lessons' => $lessons,
            'assessments' => $assessments,
            'users' => $users,
        ]);
    }

    // Update an access record
    public function update(Request $request, $id)
    {
        $access = Access::findOrFail($id);

        $data = $request->validate([
            'child_id' => 'required|exists:children,id',
            'lesson_id' => 'nullable|exists:lessons,id',
            'assessment_id' => 'nullable|exists:assessments,id',
            'due_date' => 'nullable|date',
            'access' => 'required|boolean',
            'payment_status' => 'required|in:paid,refunded,disputed,failed',
            'refund_id' => 'nullable|string',
            'metadata' => 'nullable|array',
        ]);

        $access->update($data);

        return back()->with('success', 'Access record updated.');
    }
}
