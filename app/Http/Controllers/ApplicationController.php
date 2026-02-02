<?php

namespace App\Http\Controllers;

use App\Mail\SendLoginCredentials;
use Illuminate\Http\Request;
use App\Models\Application;
use App\Models\User;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Mail;
use App\Mail\VerifyApplicationEmail;
use App\Models\AdminTask;
use App\Models\Child;
use App\Models\Permission;
use App\Services\BillingService;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

class ApplicationController extends Controller
{
     protected BillingService $billing;

    public function __construct(BillingService $billing)
    {
        $this->billing = $billing;
    }
    public function create()
    {
        return Inertia::render('@public/Applications/CreateApplication');
    }
    public function store(Request $request)
    {
        /* ───────────── 1) VALIDATE ───────────── */
        $rules = [
            'applicant_name'   => ['required', 'string', 'max:255'],
            'email'            => ['required', 'email', 'max:255',
                                   Rule::unique('applications', 'email')],
            'phone_number'     => ['nullable', 'string', 'max:20'],
            'address_line1'    => ['required', 'string', 'max:255'],
            'address_line2'    => ['required', 'string', 'max:255', 'regex:/^([A-Za-z]{1,2}\\d[A-Za-z\\d]?\\s*\\d[A-Za-z]{2})$/'],
            'mobile_number'    => ['required', 'string', 'max:20'],
            'referral_source'  => ['nullable', 'string', 'max:255'],
            'terms_accepted'   => ['accepted'],
            'application_type' => ['required', 'in:Type1,Type2,Type3'],
            'organization_id'  => ['required', 'integer', 'exists:organizations,id'],

            /*
             | Signature may arrive in TWO forms:
             | 1) An uploaded file     ( $request->file('signature') )
             | 2) A base-64 PNG string ( $request->input('signature') )
             | We validate with a custom closure that accepts either.
             */
            'signature'        => ['required', function ($attribute, $value, $fail) use ($request) {
                if ($request->hasFile('signature')) {
                    $file = $request->file('signature');
                    if (! $file->isValid()) {
                        return $fail('Uploaded signature file is invalid.');
                    }

                    $ext = strtolower($file->getClientOriginalExtension());
                    if (! in_array($ext, ['png', 'jpg', 'jpeg', 'pdf'], true)) {
                        return $fail('Signature must be PNG, JPG/JPEG, or PDF.');
                    }

                    if ($file->getSize() > 2 * 1024 * 1024) { // 2 MB
                        return $fail('Signature file must not exceed 2 MB.');
                    }
                    return;
                }

                // No file – expect base-64
                if (is_string($value) &&
                    preg_match('/^data:image\/\w+;base64,/', $value) === 1) {
                    return; // valid base-64 string
                }

                $fail('Signature is required (draw or upload).');
            }],
        ];

        /*  Extra child rules for Type2 */
        if ($request->input('application_type') === 'Type2') {
            $rules += [
                'children'                          => ['required', 'array', 'min:1'],
                'children.*.name'                   => ['required', 'string', 'max:255'],
                'children.*.age'                    => ['required', 'integer', 'min:1'],
                'children.*.school_name'            => ['required', 'string', 'max:255'],
                'children.*.area'                   => ['required', 'string', 'max:255'],
                'children.*.year_group'             => ['required', 'string', 'max:255'],
                'children.*.date_of_birth'          => ['required', 'date'],
                'children.*.emergency_contact_name' => ['required', 'string', 'max:255'],
                'children.*.emergency_contact_phone'=> ['required', 'string', 'max:50'],
                'children.*.academic_info'          => ['nullable', 'string'],
                'children.*.previous_grades'        => ['nullable', 'string'],
                'children.*.medical_info'           => ['nullable', 'string'],
                'children.*.additional_info'        => ['nullable', 'string'],
            ];
        }

        $validated = $request->validate($rules);

        /* ───────────── 2) STORE SIGNATURE ───────────── */
        $signaturePath = null;

        if ($request->hasFile('signature')) {
            // file upload path:  storage/app/public/signatures/…
            $signaturePath = $request->file('signature')
                                     ->store('signatures', 'public');

        } else { // base-64 PNG
            $sigData = $request->input('signature');                         // data:image/png;base64,AAA…
            $raw     = base64_decode(preg_replace('/^data:image\/\w+;base64,/', '', $sigData));
            $name    = 'signatures/' . Str::uuid() . '.png';

            Storage::disk('public')->put($name, $raw);
            $signaturePath = $name;
        }

        /* ───────────── 3) ADD EXTRA COLUMNS ───────────── */
        $extra = [
            'application_id'     => (string) Str::uuid(),
            'submitted_date'     => now(),
            'application_status' => 'Pending',
            'verification_token' => Str::random(60),
            'signature_path'     => $signaturePath,
        ];

        if ($validated['application_type'] === 'Type2') {
            $extra['children_data'] = json_encode($validated['children']);
        }

        /* ───────────── 4) SAVE  ───────────── */
        $application = Application::create(array_merge(
            Arr::except($validated, ['signature', 'children', 'terms_accepted']),
            $extra
        ));

        /* ───────────── 5) EMAIL VERIFICATION  ───────────── */
        Mail::to($application->email)
            ->send(new VerifyApplicationEmail($application));

        return redirect()
            ->route('application.verification')
            ->with([
                'status'  => 'verification-link-sent',
                'email'   => $application->email,
                'success' => 'Application submitted! Check your e-mail to verify.',
            ]);
    }

    public function verifyEmail($token)
    {
        $application = Application::where('verification_token', $token)->firstOrFail();

        $application->update(['verified_at' => now()]);

        // Type1: auto-approve, create User, send credentials
        if ($application->application_type === 'Type1') {
            $application->update(['application_status' => 'Approved']);
            $password=Str::random(8);
            
            // Get organization_id from application (sent from frontend)
            $organizationId = $application->organization_id ?? 2;
            
            $user = User::firstOrCreate(
                ['email' => $application->email],
                [
                    'name'                    => $application->applicant_name,
                    'password'                => bcrypt($password),
                    'role'                    => 'parent',
                    'email_verified_at'       => now(),
                    'address_line1'           => $application->address_line1,
                    'address_line2'           => $application->address_line2,
                    'mobile_number'           => $application->mobile_number,
                    'current_organization_id' => $organizationId,
                ]
            );
        if (! $user->billing_customer_id) {
            $custId = $this->billing->createCustomer($user);
            Log::info('Billing customer creation', [
                'user_id' => $user->id,
                'user_email' => $user->email,
                'custId' => $custId,
            ]);
            if ($custId) {
                $user->billing_customer_id = $custId;
                $user->saveQuietly();
                Log::info('Saved billing_customer_id', [
                    'user_id' => $user->id,
                    'billing_customer_id' => $user->billing_customer_id,
                ]);
            }
        }
            
            // Attach to organization pivot table (same as teacher registration)
            if (!$user->organizations()->where('organization_id', $organizationId)->exists()) {
                $user->organizations()->attach($organizationId, [
                    'role' => 'parent',
                    'status' => 'active',
                    'invited_by' => null,
                    'joined_at' => now(),
                ]);
            }
            
            Mail::to($user->email)
                ->send(new SendLoginCredentials($user, $password));

            $application->update(['user_id' => $user->id]);

            // Create a permission record (no children in Type1)
            // Permission::create([
            //   'user_id'           => $user->id,
            //   'child_id'          => null,
            //   'terms_accepted_at' => now(),
            //   'signature_path'    => $application->signature_path,
            // ]);
        }

        // Type2: queue admin review
        if ($application->application_type === 'Type2') {
            AdminTask::create([
                'task_type'     => 'Application Review',
                'assigned_to'   => null,
                'status'        => 'Pending',
                'related_entity' => route('applications.edit', $application->application_id),
                'priority'      => 'High',
            ]);
        }

        return redirect()->route('email.verified')
            ->with('success', 'Email verified!')
            ->with('application_type', $application->application_type);
    }
    public function emailVerified()
    {
        return Inertia::render('@public/Applications/EmailVerified', [
            'status' => session('status'),
            'email'           => session('email'),
            'membershipType'  => session('application_type'),
        ]);
    }
    public function reviewApplication($id, Request $request)
    {
        log::info("Reviewing application with ID: $request");
        $application = Application::findOrFail($id);

        $request->validate([
            'status'         => 'required|in:Approved,Rejected',
            'admin_feedback' => 'nullable|string',
        ]);

        $application->update([
            'application_status' => $request->status,
            'admin_feedback'     => $request->admin_feedback,
            'reviewer_id'        => auth()->id(),
        ]);
        $password=Str::random(8);
        // On approval, create User + Children + Permissions
        if ($request->status === 'Approved') {
            // Get organization_id from application (sent from frontend)
            $organizationId = $application->organization_id ?? 2;
            
            // 1) Create or fetch User
            $user = User::firstOrCreate(
                ['email' => $application->email],
                [
                    'name'                    => $application->applicant_name,
                    'password'                => bcrypt($password),
                    'role'                    => 'parent',
                    'email_verified_at'       => now(),
                    'address_line1'           => $application->address_line1,
                    'address_line2'           => $application->address_line2,
                    'mobile_number'           => $application->mobile_number,
                    'current_organization_id' => $organizationId,
                ]
            );
             if (! $user->billing_customer_id) {
            $custId = $this->billing->createCustomer($user);
            Log::info('Billing customer creation', [
                'user_id' => $user->id,
                'user_email' => $user->email,
                'custId' => $custId,
            ]);
            if ($custId) {
                $user->billing_customer_id = $custId;
                $user->saveQuietly();
                Log::info('Saved billing_customer_id', [
                    'user_id' => $user->id,
                    'billing_customer_id' => $user->billing_customer_id,
                ]);
            }
        }
            
            // Attach to organization pivot table (same as teacher registration)
            if (!$user->organizations()->where('organization_id', $organizationId)->exists()) {
                $user->organizations()->attach($organizationId, [
                    'role' => 'parent',
                    'status' => 'active',
                    'invited_by' => null,
                    'joined_at' => now(),
                ]);
            }
            
            Mail::to($user->email)
                ->send(new SendLoginCredentials($user, $password));

            $application->update(['user_id' => $user->id]);

            // 2) Create Child records
            $children = json_decode($application->children_data, true) ?? [];
            foreach ($children as $childData) {
                $child = Child::create([
                    'application_id'          => $application->application_id,
                    'user_id'                 => $user->id,
                    'child_name'              => $childData['name'],
                    'age'                     => $childData['age'],
                    'school_name'             => $childData['school_name'],
                    'area'                    => $childData['area'],
                    'year_group'              => $childData['year_group'],
                    'date_of_birth'           => $childData['date_of_birth'] ?? null,
                    'emergency_contact_name'  => $childData['emergency_contact_name']  ?? null,
                    'emergency_contact_phone' => $childData['emergency_contact_phone'] ?? null,
                    'academic_info'           => $childData['academic_info']           ?? null,
                    'previous_grades'         => $childData['previous_grades']         ?? null,
                    'medical_info'            => $childData['medical_info']            ?? null,
                    'additional_info'         => $childData['additional_info']         ?? null,
                    'organization_id'         => $organizationId,

                ]);

                // 3) Create a Permission for this child
                Permission::create([
                    'user_id'           => $user->id,
                    'child_id'          => $child->id,
                    'terms_accepted_at' => now(),
                    'signature_path'    => $application->signature_path,
                ]);
            }

            // 4) Clear JSON so we don’t re-insert on a second review
            $application->update(['children_data' => null]);
        }

        return redirect()->route('applications.index')
            ->with('success', 'Application reviewed successfully!');
    }
    public function edit($id)
    {
        // Find the application by its ID
        $application = Application::findOrFail($id);

        // Return the edit page with the application data
        return Inertia::render('@admin/Applications/EditApplication', [
            'application' => $application
        ]);
    }
    // Admin reviews an application (approve or reject)
    // public function reviewApplication($id, Request $request)
    // {
    //     $application = Application::findOrFail($id);

    //     // Validate admin response (approve or reject)
    //     $request->validate([
    //         'status' => 'required|in:Approved,Rejected',
    //         'admin_feedback' => 'nullable|string',
    //     ]);

    //     // Update application status
    //     $application->update([
    //         'application_status' => $request->status,
    //         'admin_feedback' => $request->admin_feedback,
    //         'reviewer_id' => null,  // Temporarily set reviewer_id as null
    //     ]);
    //     if ($request->status === 'Approved') {
    //         // Create a user when approved
    //         $user = User::firstOrCreate(
    //             ['email' => $application->email],  // Look for existing user by email
    //             [
    //                 'name' => $application->applicant_name,
    //                 'password' => bcrypt(Str::random(8)),
    //                 'role' => 'parent',
    //                 'email_verified_at' => now(),
    //                 // 'email_verified_at' => now(),
    //             ]
    //         );

    //         // If the user is newly created, send login credentials
    //         // if ($user->wasRecentlyCreated) {
    //             Mail::to($user->email)->send(new SendLoginCredentials($user, $user->password));
    //         // }

    //         // Update the application with the user_id
    //         $application->update(['user_id' => $user->id, 'verified_at' => now()]);
    //     }

    //     if (
    //         $request->status === 'Approved' &&
    //         $application->application_type === 'Type2' &&          // full membership
    //         $application->verified_at                              // e-mail verified
    //     ) {
    //         $children = json_decode($application->children_data, true);

    //         if (is_array($children) && count($children)) {
    //             foreach ($children as $child) {
    //                Child::create([
    //                     'application_id'       => $application->application_id,
    //                     'user_id'               => $user->id,      // ← NEW FK
    //                     'child_name'           => $child['name'],
    //                     'age'                  => $child['age'],
    //                     'school_name'          => $child['school_name'],
    //                     'area'                 => $child['area'],
    //                     'year_group'           => $child['year_group'],
    //                     'learning_difficulties'=> $child['learning_difficulties'] ?? null,
    //                     'focus_targets'        => $child['focus_targets'] ?? null,
    //                     'other_information'    => $child['other_information'] ?? null,
    //                 ]);
    //                 Child::where('application_id', $application->application_id)
    //              ->whereNull('user_id')
    //              ->update(['user_id' => $user->id]);
    //             }

    //             // optional: clear the stored JSON so we never double-insert
    //             $application->update(['children_data' => null]);
    //         }
    //     }



    //     return redirect()->route('applications.index')
    //                      ->with('success', 'Application reviewed successfully!');
    // }

    public function index(Request $request)
    {
        $query = Application::query();

        // Apply search filter
        if ($request->filled('search')) {
            $search = $request->input('search');
            $query->where(function ($q) use ($search) {
                $q->where('applicant_name', 'like', "%{$search}%")
                  ->orWhere('email', 'like', "%{$search}%")
                  ->orWhere('application_type', 'like', "%{$search}%");
            });
        }

        // Apply status filter
        if ($request->filled('status') && $request->input('status') !== 'all') {
            $query->where('application_status', $request->input('status'));
        }

        // Apply type filter
        if ($request->filled('type') && $request->input('type') !== 'all') {
            $query->where('application_type', $request->input('type'));
        }

        // Apply sorting
        switch ($request->input('sort', 'newest')) {
            case 'newest':
                $query->orderBy('created_at', 'desc');
                break;
            case 'oldest':
                $query->orderBy('created_at', 'asc');
                break;
            case 'name':
                $query->orderBy('applicant_name', 'asc');
                break;
            case 'status':
                $query->orderBy('application_status', 'asc');
                break;
            default:
                $query->orderBy('created_at', 'desc');
                break;
        }

        $applications = $query->paginate(10)->withQueryString();

        return Inertia::render('@admin/Applications/IndexApplication', [
            'applications' => $applications,
            'filters' => $request->only(['search', 'status', 'type', 'sort']),
        ]);
    }
    public function show($id)
    {
        // Find the application by its ID
        $application = Application::findOrFail($id);

        // Return the show page with the application data
        return Inertia::render('@admin/Applications/ShowApplication', [
            'application' => $application
        ]);
    }
    public function destroy($id)
    {
        // Find the application by its ID
        $application = Application::findOrFail($id);

        // Delete the application
        $application->delete();

        return redirect()->route('applications.index')
            ->with('success', 'Application deleted successfully!');
    }

    public function verificationPage()
    {
        return Inertia::render('@public/Applications/VerificationSent', [
            'status' => session('status'),
            'email' => session('email'),
        ]);  // Adjust this to your React component name
    }
    public function resendVerificationEmail(Request $request)
    {
        $request->validate([
            'email' => 'required|email|exists:applications,email', // Validate the email is in the database
        ]);

        // Find the application by email
        $application = Application::where('email', $request->email)->first();

        if ($application) {
            // Send the verification email
            Mail::to($application->email)->send(new VerifyApplicationEmail($application));

            return redirect()->route('application.verification')
                ->with('status', 'verification-link-sent')
                ->with('success', 'Verification email resent successfully!');
        }

        return redirect()->route('application.verification')
            ->with('error', 'Application not found!');
    }
}
