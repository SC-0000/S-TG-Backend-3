<?php

namespace App\Http\Controllers;

use App\Models\Organization;
use App\Models\SystemSetting;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

class OrganizationController extends Controller
{
    /**
     * Display a listing of organizations
     */
    public function index()
    {
        $user = Auth::user();
        
        // Super admins can see all organizations, others see only their organizations
        if ($user->isAdmin()) {
            $organizations = Organization::with(['owner', 'users'])
                                       ->withCount(['users', 'articles', 'assessments', 'liveLessonSessions', 'contentLessons', 'services'])
                                       ->latest()
                                       ->paginate(15);
        } else {
            $organizations = $user->organizations()
                                 ->with(['owner', 'users'])
                                 ->withCount(['users', 'articles', 'assessments', 'liveLessonSessions', 'contentLessons', 'services'])
                                 ->latest()
                                 ->paginate(15);
        }

        return Inertia::render('@admin/Organizations/Index', [
            'organizations' => $organizations,
            'canCreateOrganization' => $user->isAdmin() || $user->ownedOrganizations()->count() < 5, // Example limit
        ]);
    }

    /**
     * Show the form for creating a new organization
     */
    public function create()
    {
        return Inertia::render('@admin/Organizations/Create');
    }

    /**
     * Store a newly created organization
     */
    public function store(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'slug' => 'nullable|string|max:255|unique:organizations,slug',
            'status' => 'required|in:active,inactive,suspended',
            'settings' => 'nullable|array',
        ]);

        DB::transaction(function () use ($request) {
            // Create organization
            $organization = Organization::create([
                'name' => $request->name,
                'slug' => $request->slug, // Will be auto-generated if not provided
                'status' => $request->status,
                'owner_id' => Auth::id(),
                'settings' => $request->settings ?? [],
            ]);

            // Add creator as super_admin
            $organization->users()->attach(Auth::id(), [
                'role' => 'super_admin',
                'status' => 'active',
                'joined_at' => now(),
            ]);

            // Set as current organization for creator
            Auth::user()->update(['current_organization_id' => $organization->id]);
        });

        return redirect()->route('organizations.index')
                        ->with('success', 'Organization created successfully.');
    }

    /**
     * Display the specified organization
     */
    public function show(Organization $organization)
    {
        // Check if user can access this organization
        if (!Auth::user()->isAdmin() && !Auth::user()->canAccessOrganization($organization->id)) {
            abort(403, 'You do not have access to this organization.');
        }

        $organization->load([
            'owner',
            'users' => function ($query) {
                $query->withPivot(['role', 'status', 'joined_at']);
            }
        ]);

        $organization->loadCount([
            'articles', 'assessments', 'liveLessonSessions', 'contentLessons', 'services', 
            'children', 'transactions', 'applications'
        ]);

        return Inertia::render('@admin/Organizations/Show', [
            'organization' => $organization,
            'userRole' => Auth::user()->getRoleInOrganization($organization->id),
            'isOwner' => Auth::user()->isOrganizationOwner($organization->id),
        ]);
    }

    /**
     * Show the form for editing the organization
     */
    public function edit(Organization $organization)
    {
        // Check permissions
        if (!Auth::user()->isAdmin() && 
            !Auth::user()->isOrganizationOwner($organization->id) && 
            !Auth::user()->hasRoleInOrganization('super_admin', $organization->id)) {
            abort(403, 'You do not have permission to edit this organization.');
        }

        return Inertia::render('@admin/Organizations/Edit', [
            'organization' => $organization,
        ]);
    }

    /**
     * Update the specified organization
     */
    public function update(Request $request, Organization $organization)
    {
        // Check permissions
        if (!Auth::user()->isAdmin() && 
            !Auth::user()->isOrganizationOwner($organization->id) && 
            !Auth::user()->hasRoleInOrganization('super_admin', $organization->id)) {
            abort(403, 'You do not have permission to update this organization.');
        }

        $request->validate([
            'name' => 'required|string|max:255',
            'slug' => [
                'nullable',
                'string',
                'max:255',
                Rule::unique('organizations', 'slug')->ignore($organization->id)
            ],
            'status' => 'required|in:active,inactive,suspended',
            'settings' => 'nullable|array',
        ]);

        $organization->update([
            'name' => $request->name,
            'slug' => $request->slug,
            'status' => $request->status,
            'settings' => $request->settings ?? $organization->settings,
        ]);

        return redirect()->route('organizations.show', $organization)
                        ->with('success', 'Organization updated successfully.');
    }

    /**
     * Remove the specified organization
     */
    public function destroy(Organization $organization)
    {
        // Only admin or organization owner can delete
        if (!Auth::user()->isAdmin() && !Auth::user()->isOrganizationOwner($organization->id)) {
            abort(403, 'You do not have permission to delete this organization.');
        }

        // Additional safety check - don't delete if it has data
        if ($organization->users()->count() > 1 || 
            $organization->articles()->count() > 0 ||
            $organization->assessments()->count() > 0 ||
            $organization->liveLessonSessions()->count() > 0 ||
            $organization->contentLessons()->count() > 0) {
            return back()->withErrors(['organization' => 'Cannot delete organization with existing data.']);
        }

        $organization->delete();

        return redirect()->route('organizations.index')
                        ->with('success', 'Organization deleted successfully.');
    }

    /**
     * Feature toggles view
     */
    public function features(Organization $organization)
    {
        if (!Auth::user()->isAdmin() && 
            !Auth::user()->isOrganizationOwner($organization->id) && 
            !Auth::user()->hasRoleInOrganization('super_admin', $organization->id)) {
            abort(403, 'You do not have permission to edit this organization.');
        }

        $defaults = config('features.defaults', []);
        $overrides = config('features.overrides', []);
        $systemOverrides = SystemSetting::getValue('feature_overrides', []);
        $orgFeatures = $organization->settings['features'] ?? [];

        $effective = array_replace_recursive(
            $defaults,
            $orgFeatures,
            $systemOverrides,
            $overrides,
        );

        return Inertia::render('@admin/Organizations/Features', [
            'organization' => $organization->only(['id', 'name']),
            'features' => $effective,
            'overrides' => array_replace_recursive($systemOverrides, $overrides),
        ]);
    }

    /**
     * Update organization feature toggles
     */
    public function updateFeatures(Request $request, Organization $organization)
    {
        if (!Auth::user()->isAdmin() && 
            !Auth::user()->isOrganizationOwner($organization->id) && 
            !Auth::user()->hasRoleInOrganization('super_admin', $organization->id)) {
            abort(403, 'You do not have permission to update this organization.');
        }

        $validated = $request->validate([
            'features' => 'required|array',
            'features.teacher.revenue_dashboard' => 'nullable|boolean',
            'features.teacher.content_edit' => 'nullable|boolean',
            'features.teacher.content_delete' => 'nullable|boolean',
            'features.parent.ai.chatbot' => 'nullable|boolean',
            'features.parent.ai.report_generation' => 'nullable|boolean',
            'features.parent.ai.post_submission_help' => 'nullable|boolean',
            'features.parent.subscriptions' => 'nullable|boolean',
        ]);

        $features = $validated['features'] ?? [];

        $flatten = function (array $array, string $prefix = '') use (&$flatten) {
            $result = [];
            foreach ($array as $key => $value) {
                $path = $prefix === '' ? $key : "{$prefix}.{$key}";
                if (is_array($value)) {
                    $result += $flatten($value, $path);
                } else {
                    $result[$path] = $value;
                }
            }
            return $result;
        };

        foreach ($flatten($features) as $path => $value) {
            // Preserve booleans only
            $organization->setFeature($path, (bool) $value);
        }

        return redirect()
            ->route('organizations.features', $organization)
            ->with('success', 'Features updated successfully.');
    }

    /**
     * Switch user's current organization
     */
    public function switch(Request $request)
    {
        $request->validate([
            'organization_id' => 'required|exists:organizations,id',
        ]);

        $user = Auth::user();
        
        if (!$user->canAccessOrganization($request->organization_id)) {
            return back()->withErrors(['organization' => 'You do not have access to this organization.']);
        }

        $user->switchOrganization($request->organization_id);
        
        $organization = Organization::find($request->organization_id);

        return back()->with('success', "Switched to {$organization->name} successfully!");
    }

    /**
     * Manage organization users
     */
    public function users(Organization $organization)
    {
        // Check permissions
        if (!Auth::user()->isAdmin() && 
            !Auth::user()->hasRoleInOrganization('super_admin', $organization->id) &&
            !Auth::user()->hasRoleInOrganization('org_admin', $organization->id)) {
            abort(403, 'You do not have permission to manage users for this organization.');
        }

        $users = $organization->users()
                             ->withPivot(['role', 'status', 'joined_at', 'invited_by'])
                             ->with(['currentOrganization'])
                             ->paginate(15);

        return Inertia::render('@admin/Organizations/Users', [
            'organization' => $organization,
            'users' => $users,
            'availableRoles' => ['super_admin', 'org_admin', 'teacher', 'parent', 'student'],
        ]);
    }

    /**
     * Add user to organization
     */
    public function addUser(Request $request, Organization $organization)
    {
        $request->validate([
            'user_id' => 'required|exists:users,id',
            'role' => 'required|in:super_admin,org_admin,teacher,parent,student',
        ]);

        // Check permissions
        if (!Auth::user()->isAdmin() && 
            !Auth::user()->hasRoleInOrganization('super_admin', $organization->id) &&
            !Auth::user()->hasRoleInOrganization('org_admin', $organization->id)) {
            abort(403, 'You do not have permission to add users to this organization.');
        }

        // Check if user is already in organization
        if ($organization->users()->where('user_id', $request->user_id)->exists()) {
            return back()->withErrors(['user_id' => 'User is already a member of this organization.']);
        }

        $organization->users()->attach($request->user_id, [
            'role' => $request->role,
            'status' => 'active',
            'invited_by' => Auth::id(),
            'joined_at' => now(),
        ]);

        return back()->with('success', 'User added to organization successfully.');
    }

    /**
     * Update user role in organization
     */
    public function updateUserRole(Request $request, Organization $organization, User $user)
    {
        $request->validate([
            'role' => 'required|in:super_admin,org_admin,teacher,parent,student',
        ]);

        // Check permissions
        if (!Auth::user()->isAdmin() && 
            !Auth::user()->hasRoleInOrganization('super_admin', $organization->id)) {
            abort(403, 'You do not have permission to update user roles.');
        }

        $organization->users()->updateExistingPivot($user->id, [
            'role' => $request->role,
        ]);

        return back()->with('success', 'User role updated successfully.');
    }

    /**
     * Remove user from organization
     */
    public function removeUser(Organization $organization, User $user)
    {
        // Check permissions
        if (!Auth::user()->isAdmin() && 
            !Auth::user()->hasRoleInOrganization('super_admin', $organization->id)) {
            abort(403, 'You do not have permission to remove users.');
        }

        // Cannot remove organization owner
        if ($organization->owner_id === $user->id) {
            return back()->withErrors(['user' => 'Cannot remove organization owner.']);
        }

        $organization->users()->detach($user->id);

        return back()->with('success', 'User removed from organization successfully.');
    }
}
