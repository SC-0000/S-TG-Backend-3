<?php

namespace App\Http\Controllers;

use App\Models\Application;
use App\Models\Child;
use App\Models\User;
use App\Models\Organization;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;

class ChildController extends Controller
{
    public function index(Request $request)
    {
        $user = Auth::user();
        
        // Super admin can filter by organization, others see only their organization
        if ($user->hasRole('super_admin') && $request->filled('organization_id')) {
            $organizationId = $request->organization_id;
        } else {
            $organizationId = $user->current_organization_id;
        }

        $children = Child::with('user')
            ->when($organizationId, function($query) use ($organizationId) {
                $query->where('organization_id', $organizationId);
            })
            ->latest()
            ->paginate(12)
            ->withQueryString();

        // Get organizations for super admin
        $organizations = null;
        if ($user->hasRole('super_admin')) {
            $organizations = Organization::orderBy('name')->get();
        }

        return Inertia::render('@admin/Children/Index', [
            'children' => $children,
            'organizations' => $organizations,
            'filters' => $request->only(['organization_id']),
        ]);
    }

    public function create()
    {
        $applications = Application::select('application_id', 'applicant_name')
        ->orderBy('created_at', 'desc')
        ->get();
          $users = User::select('id', 'name')
            ->orderBy('name')
            ->get();

return Inertia::render('@admin/Children/Create', [
'applications' => $applications,
            'users' => $users
]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            // Required
            'child_name'            => 'required|string|max:255',
            // Optional fields
            'age'                   => 'nullable|integer|min:1',
            'school_name'           => 'nullable|string|max:255',
            'area'                  => 'nullable|string|max:255',
            'year_group'            => 'nullable|string|max:255',
            'learning_difficulties' => 'nullable|string',
            'focus_targets'         => 'nullable|string',
            'other_information'     => 'nullable|string',
            // Link to existing application (if any)
            // Link to an existing user (parent/guardian)
            'user_id'               => 'nullable|exists:users,id',
            // New fields from the extended schema:
            'date_of_birth'           => 'nullable|date',
            'emergency_contact_name'  => 'nullable|string|max:255',
            'emergency_contact_phone' => 'nullable|string|max:50',
            'academic_info'           => 'nullable|string',
            'previous_grades'         => 'nullable|string',
            'medical_info'            => 'nullable|string',
            'additional_info'         => 'nullable|string',
        ]);
         $application = Application::query()
        ->where('user_id', $data['user_id'])              // linked to that parent
        ->where('application_status', 'Approved')         // or whatever rule you want
        ->latest('submitted_date')                        // pick the newest
        ->first();
        if (! $application) {
        return back()
            ->withErrors(['user_id' => 'That guardian has no approved application yet.'])
            ->withInput();
    }
     $data['application_id'] = $application->application_id;
        // Create the Child record with all validated data
        $child = Child::create($data);

        return redirect()
            ->route('children.show', $child)
            ->with('success', 'Child created successfully.');
    }

    public function show(Child $child)
    {
        // eager-load the related user
        $child->load('user');

        return Inertia::render('@admin/Children/Show', [
            'child' => $child,
            'user' => $child->user,
        ]);
    }

    public function edit(Child $child)
    {
        // Fetch all applications (ID + applicant_name)
        $applications = Application::select('application_id', 'applicant_name')
            ->orderBy('created_at', 'desc')
            ->get();

        // Fetch all users (ID + name)
        $users = User::select('id', 'name')
            ->orderBy('name')
            ->get();

        return Inertia::render('@admin/Children/Edit', [
            'child'        => $child,
            'applications' => $applications,
            'users'        => $users,
        ]);
    }

    public function update(Request $request, Child $child)
    {
        $data = $request->validate([
            'child_name'            => 'required|string|max:255',
            'age'                   => 'nullable|integer|min:1',
            'school_name'           => 'nullable|string|max:255',
            'area'                  => 'nullable|string|max:255',
            'year_group'            => 'nullable|string|max:255',
            'learning_difficulties' => 'nullable|string',
            'focus_targets'         => 'nullable|string',
            'other_information'     => 'nullable|string',
            'application_id'        => 'nullable|exists:applications,application_id',
            'user_id'               => 'nullable|exists:users,id',
            'date_of_birth'           => 'nullable|date',
            'emergency_contact_name'  => 'nullable|string|max:255',
            'emergency_contact_phone' => 'nullable|string|max:50',
            'academic_info'           => 'nullable|string',
            'previous_grades'         => 'nullable|string',
            'medical_info'            => 'nullable|string',
            'additional_info'         => 'nullable|string',
        ]);

        $child->update($data);

        return redirect()
            ->route('children.show', $child)
            ->with('success', 'Child profile updated successfully.');
    }
    public function destroy(Child $child)
    {
        $child->delete();
        return redirect()->route('children.index')
                         ->with('success', 'Child deleted');
    }
}
