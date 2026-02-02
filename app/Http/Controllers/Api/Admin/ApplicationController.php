<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Api\ApiController;
use App\Http\Requests\Api\Applications\ApplicationReviewRequest;
use App\Http\Resources\ApplicationResource;
use App\Mail\SendLoginCredentials;
use App\Models\Application;
use App\Models\Child;
use App\Models\Permission;
use App\Models\User;
use App\Services\BillingService;
use App\Support\ApiPagination;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

class ApplicationController extends ApiController
{
    protected BillingService $billing;

    public function __construct(BillingService $billing)
    {
        $this->billing = $billing;
    }

    public function index(Request $request): JsonResponse
    {
        $query = Application::query();

        $this->applyOrgScope($request, $query);

        if ($request->filled('search')) {
            $search = $request->query('search');
            $query->where(function ($q) use ($search) {
                $q->where('applicant_name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%")
                    ->orWhere('application_type', 'like', "%{$search}%");
            });
        }

        if ($request->filled('status') && $request->query('status') !== 'all') {
            $query->where('application_status', $request->query('status'));
        }

        if ($request->filled('type') && $request->query('type') !== 'all') {
            $query->where('application_type', $request->query('type'));
        }

        switch ($request->query('sort', 'newest')) {
            case 'oldest':
                $query->orderBy('created_at', 'asc');
                break;
            case 'name':
                $query->orderBy('applicant_name', 'asc');
                break;
            case 'status':
                $query->orderBy('application_status', 'asc');
                break;
            case 'newest':
            default:
                $query->orderBy('created_at', 'desc');
                break;
        }

        $applications = $query->paginate(ApiPagination::perPage($request, 10));
        $data = ApplicationResource::collection($applications->items())->resolve();

        return $this->paginated($applications, $data);
    }

    public function show(Request $request, Application $application): JsonResponse
    {
        if ($response = $this->ensureScope($request, $application)) {
            return $response;
        }

        $application->load('user');
        $data = (new ApplicationResource($application))->resolve();

        return $this->success($data);
    }

    public function review(ApplicationReviewRequest $request, Application $application): JsonResponse
    {
        if ($response = $this->ensureScope($request, $application)) {
            return $response;
        }

        $validated = $request->validated();

        $application->update([
            'application_status' => $validated['status'],
            'admin_feedback' => $validated['admin_feedback'] ?? null,
            'reviewer_id' => $request->user()?->id,
        ]);

        if ($validated['status'] === 'Approved') {
            $organizationId = $application->organization_id ?? 2;
            $password = Str::random(8);

            $user = User::firstOrCreate(
                ['email' => $application->email],
                [
                    'name' => $application->applicant_name,
                    'password' => bcrypt($password),
                    'role' => 'parent',
                    'email_verified_at' => now(),
                    'address_line1' => $application->address_line1,
                    'address_line2' => $application->address_line2,
                    'mobile_number' => $application->mobile_number,
                    'current_organization_id' => $organizationId,
                ]
            );

            if (!$user->billing_customer_id) {
                $custId = $this->billing->createCustomer($user);
                Log::info('Billing customer creation', [
                    'user_id' => $user->id,
                    'user_email' => $user->email,
                    'custId' => $custId,
                ]);
                if ($custId) {
                    $user->billing_customer_id = $custId;
                    $user->saveQuietly();
                }
            }

            if (!$user->organizations()->where('organization_id', $organizationId)->exists()) {
                $user->organizations()->attach($organizationId, [
                    'role' => 'parent',
                    'status' => 'active',
                    'invited_by' => null,
                    'joined_at' => now(),
                ]);
            }

            Mail::to($user->email)->send(new SendLoginCredentials($user, $password));

            $application->update(['user_id' => $user->id]);

            $children = json_decode($application->children_data, true) ?? [];
            foreach ($children as $childData) {
                $child = Child::create([
                    'application_id' => $application->application_id,
                    'user_id' => $user->id,
                    'child_name' => $childData['name'] ?? null,
                    'age' => $childData['age'] ?? null,
                    'school_name' => $childData['school_name'] ?? null,
                    'area' => $childData['area'] ?? null,
                    'year_group' => $childData['year_group'] ?? null,
                    'date_of_birth' => $childData['date_of_birth'] ?? null,
                    'emergency_contact_name' => $childData['emergency_contact_name'] ?? null,
                    'emergency_contact_phone' => $childData['emergency_contact_phone'] ?? null,
                    'academic_info' => $childData['academic_info'] ?? null,
                    'previous_grades' => $childData['previous_grades'] ?? null,
                    'medical_info' => $childData['medical_info'] ?? null,
                    'additional_info' => $childData['additional_info'] ?? null,
                    'organization_id' => $organizationId,
                ]);

                Permission::create([
                    'user_id' => $user->id,
                    'child_id' => $child->id,
                    'terms_accepted_at' => now(),
                    'signature_path' => $application->signature_path,
                ]);
            }

            $application->update(['children_data' => null]);
        }

        $application->load('user');
        $data = (new ApplicationResource($application))->resolve();

        return $this->success([
            'application' => $data,
            'message' => 'Application reviewed successfully.',
        ]);
    }

    public function destroy(Request $request, Application $application): JsonResponse
    {
        if ($response = $this->ensureScope($request, $application)) {
            return $response;
        }

        $application->delete();

        return $this->success(['message' => 'Application deleted successfully.']);
    }

    private function ensureScope(Request $request, Application $application): ?JsonResponse
    {
        $user = $request->user();
        if (!$user) {
            return $this->error('Unauthenticated.', [], 401);
        }

        $orgId = $request->attributes->get('organization_id');
        if ($user->isSuperAdmin() && $request->filled('organization_id')) {
            $orgId = $request->integer('organization_id');
        }

        if ($orgId && (int) $application->organization_id !== (int) $orgId) {
            return $this->error('Not found.', [], 404);
        }

        return null;
    }

    private function applyOrgScope(Request $request, $query): void
    {
        $user = $request->user();
        if (!$user) {
            return;
        }

        $orgId = $request->attributes->get('organization_id');
        if ($user->isSuperAdmin() && $request->filled('organization_id')) {
            $orgId = $request->integer('organization_id');
        }

        if ($orgId) {
            $query->where('organization_id', $orgId);
        }
    }
}
