<?php

namespace App\Http\Controllers;

use App\Mail\FeedbackConfirmationEmail;
use App\Support\MailContext;
use Illuminate\Http\Request;
use App\Models\Feedback;
use Illuminate\Support\Facades\Mail;
use Inertia\Inertia;

class FeedbackController extends Controller
{
    public function create()
    {
        // Render the Inertia component for creating feedback.
        return Inertia::render('@admin/Feedback/Create');
    }

    public function store(Request $request)
    {
        // Validate incoming feedback data.
        $validatedData = $request->validate([
            // Optional: If the user is logged in, they can provide a user id.
            'user_id'   => 'nullable|string|max:255',
            // Name of the feedback provider.
            'name'      => 'required|string',
            // Email address is required.
            'user_email'=> 'required|email|max:255',
            // Category must be one of the allowed options.
            'category'  => 'required|in:Inquiry,Complaint,Suggestion,Support',
            // Message is required.
            'message'   => 'required|string',
            // Attachments: we can process these as file uploads.
        ]);

        // Process file uploads for attachments if provided.
        $attachmentPaths = [];
        if ($request->hasFile('attachments')) {
            foreach ($request->file('attachments') as $file) {
                // Save the file in the "feedback" directory on the public disk.
                $path = $file->store('feedback', 'public');
                $attachmentPaths[] = $path;
            }
        }
        $validatedData['attachments'] = count($attachmentPaths) ? $attachmentPaths : null;

        // Set additional fields that are not provided by the form.
        $validatedData['status'] = 'Pending';
        // Set the submission date automatically.
        $validatedData['submission_date'] = now();
        // Get the user's IP address.
        $validatedData['user_ip'] = $request->ip();
        $validatedData['organization_id'] = $request->user()?->current_organization_id;

        // Create the feedback record.
        $feedback=Feedback::create($validatedData);

        // Send confirmation email to the user who submitted the feedback
    $organization = MailContext::resolveOrganization($feedback->organization_id ?? null, null, $feedback);
    Mail::to($feedback->user_email)->send(new FeedbackConfirmationEmail($feedback, $organization));

    // Redirect with success message
    return redirect()->route('feedback.success', ['feedback' => $feedback->id])
                     ->with('success', 'Thank you for your feedback! A confirmation email has been sent to you.');
    }
    // List all feedback entries.
    public function success($feedbackId)
{
    // Retrieve the feedback record by ID
    $feedback = Feedback::findOrFail($feedbackId);

    // Render the success page with the feedback data
    return Inertia::render('@admin/Feedback/Success', ['feedback' => $feedback]);
}
public function index()
{
    $user = auth()->user();
    $query = Feedback::query();

    if ($user?->role === 'super_admin' && request()->filled('organization_id')) {
        $query->forOrganization(request()->organization_id);
    } elseif ($user?->role !== 'super_admin' && $user?->current_organization_id) {
        $query->forOrganization($user->current_organization_id);
    }

    $feedbacks = $query->get();
    $organizations = $user?->role === 'super_admin'
        ? \App\Models\Organization::orderBy('name')->get()
        : null;

    return Inertia::render('@admin/Feedback/IndexFeedback', [
        'feedbacks' => $feedbacks,
        'organizations' => $organizations,
        'filters' => request()->only('organization_id'),
    ]);
}

// Display a single feedback entry.
public function show($id)
{
    $user = auth()->user();
    $orgId = $user?->current_organization_id;
    $feedback = Feedback::when(
        $user?->role !== 'super_admin' && $orgId,
        fn($q) => $q->forOrganization($orgId)
    )->findOrFail($id);
    return Inertia::render('@admin/Feedback/ShowFeedback', ['feedback' => $feedback]);
}

// Show form to edit a feedback (e.g. to add admin response).
public function edit($id)
{
    $user = auth()->user();
    $orgId = $user?->current_organization_id;
    $feedback = Feedback::when(
        $user?->role !== 'super_admin' && $orgId,
        fn($q) => $q->forOrganization($orgId)
    )->findOrFail($id);
    return Inertia::render('@admin/Feedback/EditFeedback', ['feedback' => $feedback]);
}

// Update feedback (e.g. admin response and status).
public function update(Request $request, $id)
{
    $user = auth()->user();
    $orgId = $user?->current_organization_id;
    $feedback = Feedback::when(
        $user?->role !== 'super_admin' && $orgId,
        fn($q) => $q->forOrganization($orgId)
    )->findOrFail($id);

    $validatedData = $request->validate([
        'status' => 'required|in:Pending,Reviewed,Resolved',
        'admin_response' => 'nullable|string',
    ]);

    $feedback->update($validatedData);

    return redirect()->route('feedbacks.show', $feedback->id)
                     ->with('success', 'Feedback updated successfully!');
}
public function destroy($id)
{
    $user = auth()->user();
    $orgId = $user?->current_organization_id;
    $feedback = Feedback::when(
        $user?->role !== 'super_admin' && $orgId,
        fn($q) => $q->forOrganization($orgId)
    )->findOrFail($id);
    $feedback->delete();

    return redirect()->route('feedbacks.index')
                     ->with('success', 'Feedback deleted successfully!');
}

}
