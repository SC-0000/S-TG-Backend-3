<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Api\ApiController;
use App\Models\Application;
use App\Models\Child;
use App\Models\Organization;
use App\Models\User;
use App\Support\ApiPagination;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ChildController extends ApiController
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        if (!$user) {
            return $this->error('Unauthenticated.', [], 401);
        }

        $orgId = $request->attributes->get('organization_id') ?: $user->current_organization_id;
        if ($user->isSuperAdmin() && $request->filled('organization_id')) {
            $orgId = $request->integer('organization_id');
        }

        $query = Child::query()
            ->with('user:id,name,email')
            ->when($orgId, fn ($q) => $q->where('organization_id', $orgId))
            ->latest();

        if ($request->filled('search')) {
            $search = $request->string('search');
            $query->where(function ($q) use ($search) {
                $q->where('child_name', 'like', "%{$search}%")
                    ->orWhereHas('user', fn ($u) => $u->where('name', 'like', "%{$search}%"));
            });
        }

        $children = $query->paginate(ApiPagination::perPage($request, 12));
        $data = collect($children->items())->map(function (Child $child) {
            return [
                'id' => $child->id,
                'child_name' => $child->child_name,
                'age' => $child->age,
                'school_name' => $child->school_name,
                'area' => $child->area,
                'year_group' => $child->year_group,
                'organization_id' => $child->organization_id,
                'user' => $child->user ? [
                    'id' => $child->user->id,
                    'name' => $child->user->name,
                    'email' => $child->user->email,
                ] : null,
                'created_at' => $child->created_at?->toISOString(),
            ];
        })->all();

        $organizations = null;
        if ($user->isSuperAdmin()) {
            $organizations = Organization::orderBy('name')->get(['id', 'name']);
        }

        return $this->paginated($children, $data, [
            'organizations' => $organizations,
            'filters' => $request->only(['organization_id', 'search']),
        ]);
    }

    public function createData(Request $request): JsonResponse
    {
        $users = User::select('id', 'name', 'email')
            ->orderBy('name')
            ->get();

        $applications = Application::select('application_id', 'applicant_name')
            ->orderBy('created_at', 'desc')
            ->get();

        return $this->success([
            'users' => $users,
            'applications' => $applications,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'child_name' => 'required|string|max:255',
            'age' => 'nullable|integer|min:1',
            'school_name' => 'nullable|string|max:255',
            'area' => 'nullable|string|max:255',
            'year_group' => 'nullable|string|max:255',
            'learning_difficulties' => 'nullable|string',
            'focus_targets' => 'nullable|string',
            'other_information' => 'nullable|string',
            'user_id' => 'nullable|exists:users,id',
            'date_of_birth' => 'nullable|date',
            'emergency_contact_name' => 'nullable|string|max:255',
            'emergency_contact_phone' => 'nullable|string|max:50',
            'academic_info' => 'nullable|string',
            'previous_grades' => 'nullable|string',
            'medical_info' => 'nullable|string',
            'additional_info' => 'nullable|string',
        ]);

        $application = null;
        if (!empty($data['user_id'])) {
            $application = Application::query()
                ->where('user_id', $data['user_id'])
                ->where('application_status', 'Approved')
                ->latest('submitted_date')
                ->first();

            if (!$application) {
                return $this->error('That guardian has no approved application yet.', [
                    ['field' => 'user_id', 'message' => 'That guardian has no approved application yet.'],
                ], 422);
            }
        }

        if ($application) {
            $data['application_id'] = $application->application_id;
            if (empty($data['organization_id'])) {
                $data['organization_id'] = $application->organization_id;
            }
        }

        $child = Child::create($data);
        $child->load('user:id,name,email');

        return $this->success([
            'child' => [
                'id' => $child->id,
                'child_name' => $child->child_name,
                'age' => $child->age,
                'school_name' => $child->school_name,
                'area' => $child->area,
                'year_group' => $child->year_group,
                'organization_id' => $child->organization_id,
                'user' => $child->user ? [
                    'id' => $child->user->id,
                    'name' => $child->user->name,
                    'email' => $child->user->email,
                ] : null,
            ],
        ], [], 201);
    }

    public function show(Request $request, Child $child): JsonResponse
    {
        $orgId = $request->attributes->get('organization_id');
        if ($orgId && (int) $child->organization_id !== (int) $orgId) {
            return $this->error('Not found.', [], 404);
        }

        $child->load('user:id,name,email');

        return $this->success([
            'child' => $child,
            'user' => $child->user,
        ]);
    }

    public function update(Request $request, Child $child): JsonResponse
    {
        $orgId = $request->attributes->get('organization_id');
        if ($orgId && (int) $child->organization_id !== (int) $orgId) {
            return $this->error('Not found.', [], 404);
        }

        $data = $request->validate([
            'child_name' => 'required|string|max:255',
            'age' => 'nullable|integer|min:1',
            'school_name' => 'nullable|string|max:255',
            'area' => 'nullable|string|max:255',
            'year_group' => 'nullable|string|max:255',
            'learning_difficulties' => 'nullable|string',
            'focus_targets' => 'nullable|string',
            'other_information' => 'nullable|string',
            'application_id' => 'nullable|exists:applications,application_id',
            'user_id' => 'nullable|exists:users,id',
            'date_of_birth' => 'nullable|date',
            'emergency_contact_name' => 'nullable|string|max:255',
            'emergency_contact_phone' => 'nullable|string|max:50',
            'academic_info' => 'nullable|string',
            'previous_grades' => 'nullable|string',
            'medical_info' => 'nullable|string',
            'additional_info' => 'nullable|string',
        ]);

        $child->update($data);
        $child->load('user:id,name,email');

        return $this->success([
            'child' => $child,
        ]);
    }

    public function destroy(Request $request, Child $child): JsonResponse
    {
        $orgId = $request->attributes->get('organization_id');
        if ($orgId && (int) $child->organization_id !== (int) $orgId) {
            return $this->error('Not found.', [], 404);
        }

        $child->delete();

        return $this->success(['message' => 'Child deleted successfully.']);
    }
}
