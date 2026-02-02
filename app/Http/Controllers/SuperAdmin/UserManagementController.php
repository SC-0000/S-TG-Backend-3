<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Organization;
use Illuminate\Http\Request;
use Inertia\Inertia;

class UserManagementController extends Controller
{
    public function index(Request $request)
    {
        $users = User::query()
            ->with([
                'organizations:id,name',
                'currentOrganization:id,name',
            ])
            ->when($request->search, fn($q) => $q->where('name', 'like', "%{$request->search}%")
                ->orWhere('email', 'like', "%{$request->search}%"))
            ->when($request->role, fn($q) => $q->where('role', $request->role))
            ->when($request->organization_id, fn($q) => $q->where('current_organization_id', $request->organization_id))
            ->paginate(20);
        
        // Get all organizations for the filter dropdown
        $organizations = Organization::orderBy('name')->get();

        return Inertia::render('@superadmin/Users/Index', [
            'users' => $users,
            'organizations' => $organizations,
            'filters' => $request->only(['search', 'role', 'organization_id']),
        ]);
    }

    public function create()
    {
        $organizations = Organization::select('id', 'name')->orderBy('name')->get();
        
        return Inertia::render('@superadmin/Users/Create', [
            'organizations' => $organizations,
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email|unique:users',
            'password' => 'required|min:8',
            'role' => 'required|in:admin,teacher,parent,super_admin',
            'current_organization_id' => 'nullable|exists:organizations,id',
        ]);

        $user = User::create([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'password' => bcrypt($validated['password']),
            'role' => $validated['role'],
            'current_organization_id' => $validated['current_organization_id'] ?? null,
        ]);

        return redirect()->route('superadmin.users.index')
            ->with('success', 'User created successfully');
    }

    public function show(User $user)
    {
        return Inertia::render('@superadmin/Users/Show', [
            'user' => $user->load(['organizations', 'children', 'subscriptions']),
        ]);
    }

    public function edit(User $user)
    {
        $organizations = Organization::select('id', 'name')->orderBy('name')->get();
        
        return Inertia::render('@superadmin/Users/Edit', [
            'user' => $user,
            'organizations' => $organizations,
        ]);
    }

    public function update(Request $request, User $user)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email|unique:users,email,' . $user->id,
            'role' => 'required|in:admin,teacher,parent,super_admin',
            'current_organization_id' => 'nullable|exists:organizations,id',
        ]);

        $originalOrgId = $user->current_organization_id;

        $user->update($validated);

        if (
            $user->role === User::ROLE_PARENT &&
            array_key_exists('current_organization_id', $validated) &&
            $validated['current_organization_id'] &&
            $validated['current_organization_id'] != $originalOrgId
        ) {
            $user->children()->update([
                'organization_id' => $validated['current_organization_id'],
            ]);
        }

        return redirect()->route('superadmin.users.index')
            ->with('success', 'User updated successfully');
    }

    public function destroy(User $user)
    {
        $user->delete();

        return redirect()->route('superadmin.users.index')
            ->with('success', 'User deleted successfully');
    }

    public function changeRole(Request $request, User $user)
    {
        $validated = $request->validate([
            'role' => 'required|in:admin,teacher,parent,super_admin',
        ]);

        $user->update(['role' => $validated['role']]);

        return back()->with('success', 'User role changed successfully');
    }

    public function toggleStatus(User $user)
    {
        // Implementation for toggling user status
        return back()->with('success', 'User status toggled');
    }

    public function impersonate(User $user)
    {
        // Implementation for impersonating user
        session(['impersonate_user_id' => $user->id]);
        return redirect('/admin/dashboard');
    }

    public function bulkAction(Request $request)
    {
        // Implementation for bulk actions
        return back()->with('success', 'Bulk action completed');
    }
}
