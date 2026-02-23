<?php

namespace App\Http\Controllers\Api\Public;

use App\Http\Controllers\Api\ApiController;
use App\Mail\SendLoginCredentials;
use App\Mail\VerifyApplicationEmail;
use App\Models\AdminTask;
use App\Models\Application;
use App\Models\Child;
use App\Models\Permission;
use App\Models\User;
use App\Services\BillingService;
use App\Support\MailContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class ApplicationController extends ApiController
{
    protected BillingService $billing;

    public function __construct(BillingService $billing)
    {
        $this->billing = $billing;
    }

    public function store(Request $request): JsonResponse
    {
        $rules = [
            'applicant_name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('applications', 'email')],
            'phone_number' => ['nullable', 'string', 'max:20'],
            'address_line1' => ['required', 'string', 'max:255'],
            'address_line2' => ['required', 'string', 'max:255', 'regex:/^([A-Za-z]{1,2}\\d[A-Za-z\\d]?\\s*\\d[A-Za-z]{2})$/'],
            'mobile_number' => ['required', 'string', 'max:20'],
            'referral_source' => ['nullable', 'string', 'max:255'],
            'terms_accepted' => ['accepted'],
            'application_type' => ['required', 'in:Type1,Type2,Type3'],
            'organization_id' => ['required', 'integer', 'exists:organizations,id'],
            'signature' => ['required', function ($attribute, $value, $fail) use ($request) {
                if ($request->hasFile('signature')) {
                    $file = $request->file('signature');
                    if (!$file->isValid()) {
                        return $fail('Uploaded signature file is invalid.');
                    }

                    $ext = strtolower($file->getClientOriginalExtension());
                    if (!in_array($ext, ['png', 'jpg', 'jpeg', 'pdf'], true)) {
                        return $fail('Signature must be PNG, JPG/JPEG, or PDF.');
                    }

                    if ($file->getSize() > 2 * 1024 * 1024) {
                        return $fail('Signature file must not exceed 2 MB.');
                    }
                    return;
                }

                if (is_string($value) && preg_match('/^data:image\\/\\w+;base64,/', $value) === 1) {
                    return;
                }

                $fail('Signature is required (draw or upload).');
            }],
        ];

        if ($request->input('application_type') === 'Type2') {
            $rules += [
                'children' => ['required', 'array', 'min:1'],
                'children.*.name' => ['required', 'string', 'max:255'],
                'children.*.age' => ['required', 'integer', 'min:1'],
                'children.*.school_name' => ['required', 'string', 'max:255'],
                'children.*.area' => ['required', 'string', 'max:255'],
                'children.*.year_group' => ['required', 'string', 'max:255'],
                'children.*.date_of_birth' => ['required', 'date'],
                'children.*.emergency_contact_name' => ['required', 'string', 'max:255'],
                'children.*.emergency_contact_phone' => ['required', 'string', 'max:50'],
                'children.*.academic_info' => ['nullable', 'string'],
                'children.*.previous_grades' => ['nullable', 'string'],
                'children.*.medical_info' => ['nullable', 'string'],
                'children.*.additional_info' => ['nullable', 'string'],
            ];
        }

        $validated = $request->validate($rules);

        $signaturePath = null;
        if ($request->hasFile('signature')) {
            $signaturePath = $request->file('signature')->store('signatures', 'public');
        } else {
            $sigData = $request->input('signature');
            $raw = base64_decode(preg_replace('/^data:image\\/\\w+;base64,/', '', $sigData));
            $name = 'signatures/' . Str::uuid() . '.png';
            Storage::disk('public')->put($name, $raw);
            $signaturePath = $name;
        }

        $extra = [
            'application_id' => (string) Str::uuid(),
            'submitted_date' => now(),
            'application_status' => 'Pending',
            'verification_token' => Str::random(60),
            'signature_path' => $signaturePath,
        ];

        if ($validated['application_type'] === 'Type2') {
            $extra['children_data'] = json_encode($validated['children']);
        }

        $application = Application::create(array_merge(
            Arr::except($validated, ['signature', 'children', 'terms_accepted']),
            $extra
        ));

        $organization = MailContext::resolveOrganization($application->organization_id ?? null, $application->user ?? null, $application);
        Mail::to($application->email)->send(new VerifyApplicationEmail($application, $organization));

        return $this->success([
            'message' => 'Application submitted. Check your e-mail to verify.',
            'application_id' => $application->application_id,
        ], [], 201);
    }

    public function verify(string $token): JsonResponse
    {
        $application = Application::where('verification_token', $token)->firstOrFail();

        if (!$application->verified_at) {
            $application->update(['verified_at' => now()]);
        }

        if ($application->application_type === 'Type1') {
            $application->update(['application_status' => 'Approved']);
            $password = Str::random(8);
            $organizationId = $application->organization_id ?? 2;

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

            $organization = MailContext::resolveOrganization($organizationId ?? null, $user);
            Mail::to($user->email)->send(new SendLoginCredentials($user, $password, $organization));

            $application->update(['user_id' => $user->id]);
        }

        if ($application->application_type === 'Type2') {
            AdminTask::create([
                'task_type' => 'Application Review',
                'assigned_to' => null,
                'status' => 'Pending',
                'related_entity' => route('applications.edit', $application->application_id),
                'priority' => 'High',
            ]);
        }

        return $this->success([
            'message' => 'Email verified.',
            'application_type' => $application->application_type,
        ]);
    }

    public function resendVerification(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'email', Rule::exists('applications', 'email')],
        ]);

        $application = Application::where('email', $validated['email'])->first();

        if (! $application) {
            return $this->error('Application not found.', [], 404);
        }

        $organization = MailContext::resolveOrganization($application->organization_id ?? null, $application->user ?? null, $application);
        Mail::to($application->email)->send(new VerifyApplicationEmail($application, $organization));

        return $this->success([
            'message' => 'Verification email resent successfully.',
        ]);
    }
}
