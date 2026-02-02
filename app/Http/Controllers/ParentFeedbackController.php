<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use App\Models\ParentFeedback;
use App\Models\AdminTask;
use App\Models\ParentFeedbacks;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;

class ParentFeedbackController extends Controller
{
    /**
     * Show the “Submit Concern” form.
     * We also need to send down the logged‐in parent’s own children.
     */
    public function create(Request $request)
    {
        // Grab only the logged‐in parent’s children for that dropdown:
        $children = $request->user()
                            ->children()
                            ->select('id','child_name')
                            ->orderBy('child_name')
                            ->get();
    
        $user = Auth::user();
        return Inertia::render('@parent/ParentFeedback/Create', [
            'childrenList' => $children,
            'user' =>$user
        ]);
    }

    /**
     * Validate and store the parent feedback, then spin off an AdminTask.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'name'         => 'required|string|max:255',
            'user_email'   => 'required|email|max:255',
            'category'     => 'required|string|max:100',
            'message'      => 'required|string',
            'feature'      => 'required|string|in:general,child_profile,billing,technical,other',
            'child_id'     => 'nullable|exists:children,id',
            'attachments'  => 'nullable|array',
            'attachments.*'=> 'file|max:5120',
        ]);

        // 1) If they chose "child_profile", ensure child_id belongs to them:
        if ($validated['feature'] === 'child_profile') {
            $ownsChild = Auth::user()
                ->children()
                ->where('id', $validated['child_id'])
                ->exists();

            if (! $ownsChild) {
                return back()
                    ->withErrors(['child_id' => 'Invalid child selected.'])
                    ->withInput();
            }
        }

        // 2) Store attachments:
        $attachedPaths = [];
        if ($request->hasFile('attachments')) {
            foreach ($request->file('attachments') as $file) {
                $attachedPaths[] = $file->store('parent_feedback_attachments', 'public');
            }
        }

        // 3) Build the “details” JSON:
        $details = [
            'feature'  => $validated['feature'],
            // only include child_id if feature is “child_profile”
            'child_id' => ($validated['feature'] === 'child_profile')
                          ? $validated['child_id']
                          : null,
        ];

        // 4) Create the ParentFeedback row:
        $pf = ParentFeedbacks::create([
            'user_id'        => Auth::id(),
            'organization_id'=> Auth::user()?->current_organization_id,
            'name'           => $validated['name'],
            'user_email'     => $validated['user_email'],
            'category'       => $validated['category'],
            'message'        => $validated['message'],
            'details'        => $details,
            'attachments'    => $attachedPaths,
            'status'         => 'New',
            'submitted_at'   => now(),
            'user_ip'        => $request->ip(),
        ]);

        // 5) Create the corresponding AdminTask:
        AdminTask::create([
            'task_type'      => 'Parent Concern',
            'assigned_to'    => null,                                          // admin can pick it up
            'status'         => 'Pending',
            // so we know which ParentFeedback this task belongs to:
            'related_entity' => route('portal.feedback.show', $pf->id),
            'priority'       => 'Medium',                                      // default (or you could let parent choose)
            'organization_id'=> Auth::user()?->current_organization_id,
        ]);

        return redirect()
            ->route('portal.feedback.create')
            ->with('success', 'Your concern has been submitted. An admin will respond soon.');
    }
    public function index()
    {
        $orgId = Auth::user()?->current_organization_id;
        // eager‐load the user who submitted
        $feedbacks = ParentFeedbacks::with('user')
            ->when(
                Auth::user()?->role === 'super_admin' && $request->filled('organization_id'),
                fn($q) => $q->forOrganization($request->organization_id)
            )
            ->when(
                Auth::user()?->role !== 'super_admin' && $orgId,
                fn($q) => $q->forOrganization($orgId)
            )
            ->orderBy('submitted_at', 'desc')
            ->paginate(20);

        // transform for Inertia
        $rows = $feedbacks->through(fn($fb) => [
            'id'             => $fb->id,
            'name'           => $fb->name,
            'user_email'     => $fb->user_email,
            'category'       => $fb->category,
            'status'         => $fb->status,
            'submitted_at'   => optional($fb->submitted_at)->toDateTimeString(),
            'user_name'      => $fb->user?->name,
        ]);
Log::info('Feedbacks fetched for admin portal', [
            'rows' => $rows,
            'user_id' => Auth::id(),
        ]);
        return Inertia::render('@admin/PortalFeedback/Index', [
            'feedbacks' => $rows,
            'organizations' => Auth::user()?->role === 'super_admin'
                ? \App\Models\Organization::orderBy('name')->get()
                : null,
            'filters' => $request->only('organization_id'),
            'links'     => $feedbacks->links(), // for pagination if desired
        ]);
    }

    /**
     * Display a single parent‐feedback.
     */
    public function show($id)
    {
        $orgId = Auth::user()?->current_organization_id;
        $requestedOrg = Auth::user()?->role === 'super_admin' ? request('organization_id') : null;
        $fb = ParentFeedbacks::with('user')
            ->when(
                $requestedOrg,
                fn($q) => $q->forOrganization($requestedOrg)
            )
            ->when(
                Auth::user()?->role !== 'super_admin' && $orgId,
                fn($q) => $q->forOrganization($orgId)
            )
            ->findOrFail($id);

        return Inertia::render('@admin/PortalFeedback/Show', [
            'feedback' => [
                'id'             => $fb->id,
                'user_id'        => $fb->user_id,
                'user_name'      => $fb->user?->name,
                'user_email'     => $fb->user_email,
                'name'           => $fb->name,
                'category'       => $fb->category,
                'message'        => $fb->message,
                'details'        => $fb->details,        // JSON‐array of whatever you stored
                'attachments'    => $fb->attachments,    // JSON array of paths/URLs
                'status'         => $fb->status,
                'admin_response' => $fb->admin_response,
                'submitted_at'   => optional($fb->submitted_at)->toDateTimeString(),
            ],
        ]);
    }

    /**
     * Update the specified feedback (i.e. “manage” = change status/admin_response).
     */
    public function update(Request $request, $id)
    {
        $orgId = Auth::user()?->current_organization_id;
        $requestedOrg = Auth::user()?->role === 'super_admin' ? $request->organization_id : null;
        $fb = ParentFeedbacks::when(
                $requestedOrg,
                fn($q) => $q->forOrganization($requestedOrg)
            )
            ->when(
                Auth::user()?->role !== 'super_admin' && $orgId,
                fn($q) => $q->forOrganization($orgId)
            )
            ->findOrFail($id);

        $validated = $request->validate([
            'status'         => 'required|in:New,Reviewed,Closed', 
            'admin_response' => 'nullable|string|max:2000',
        ]);

        $fb->update($validated);

        return redirect()->route('portal.feedback.show', $fb->id)
                         ->with('success', 'Feedback updated successfully.');
    }
}
