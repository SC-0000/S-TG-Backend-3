<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Api\Auth\TeacherRegistrationController as ApiTeacherRegistrationController;
use App\Models\User;
use App\Models\AdminTask;
use App\Mail\TeacherApplicationReceived;
use App\Mail\TeacherApproved;
use App\Mail\TeacherRejected;
use App\Mail\GuestVerificationCode;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Validator;
use Inertia\Inertia;
use Illuminate\Support\Facades\Log;
class TeacherController extends Controller
{
    /**
     * Send OTP to teacher's email for verification
     */
    public function sendOtp(Request $request)
    {
        if ($request->expectsJson()) {
            return app(ApiTeacherRegistrationController::class)->sendOtp($request);
        }
        $request->validate([
            'email' => 'required|email|unique:users,email',
        ], [
            'email.unique' => 'This email is already registered.'
        ]);

        // Generate 6-digit OTP
        $otp = str_pad(random_int(0, 999999), 6, '0', STR_PAD_LEFT);

        // Store OTP in session with expiry (5 minutes)
        session([
            'teacher_registration_otp' => $otp,
            'teacher_registration_email' => $request->email,
            'teacher_otp_expires_at' => now()->addMinutes(5),
        ]);

        // Send OTP via email
        Mail::to($request->email)->send(new GuestVerificationCode($otp));

        return response()->json([
            'success' => true,
            'message' => 'Verification code sent to your email.'
        ]);
    }

    /**
     * Verify OTP
     */
    public function verifyOtp(Request $request)
    {
        if ($request->expectsJson()) {
            return app(ApiTeacherRegistrationController::class)->verifyOtp($request);
        }
        $request->validate([
            'email' => 'required|email',
            'otp' => 'required|string|size:6',
        ]);

        $storedOtp = session('teacher_registration_otp');
        $storedEmail = session('teacher_registration_email');
        $expiresAt = session('teacher_otp_expires_at');

        // Check if OTP exists and hasn't expired
        if (!$storedOtp || !$expiresAt || now()->greaterThan($expiresAt)) {
            return response()->json([
                'success' => false,
                'message' => 'Verification code has expired. Please request a new one.'
            ], 422);
        }

        // Check if email matches
        if ($storedEmail !== $request->email) {
            return response()->json([
                'success' => false,
                'message' => 'Email does not match.'
            ], 422);
        }

        // Verify OTP
        if ($storedOtp !== $request->otp) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid verification code.'
            ], 422);
        }

        // Mark as verified
        session(['teacher_email_verified' => true]);

        return response()->json([
            'success' => true,
            'message' => 'Email verified successfully.'
        ]);
    }

    /**
     * Register a new teacher (pending approval)
     */
    public function register(Request $request)
    {
        if ($request->expectsJson()) {
            return app(ApiTeacherRegistrationController::class)->register($request);
        }
        Log::info('Register teacher request received', [
            'email' => $request->input('email'),
            'ip' => $request->ip(),
        ]);

        // Check if email was verified
        if (!session('teacher_email_verified')) {
            Log::warning('Teacher registration attempted without email verification', [
                'email' => $request->input('email'),
            ]);

            throw \Illuminate\Validation\ValidationException::withMessages([
                'email' => ['Please verify your email first.']
            ]);
        }
log::info('Email verified for teacher registration', [
            'email' => $request->input('email'),
        ]);
        $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email|unique:users,email',
            'password' => 'required|string|min:8|confirmed',
            'mobile_number' => 'nullable|string|max:20',
            'qualifications' => 'nullable|string',
            'experience' => 'nullable|string',
            'specialization' => 'nullable|string',
            'organization_id' => 'required|integer|exists:organizations,id',
        ]);

        Log::debug('Teacher registration data validated', [
            'email' => $request->input('email'),
            'organization_id' => $request->input('organization_id'),
        ]);

        try {
            // Create user with 'teacher' role and 'pending_approval' status in metadata
            $user = User::create([
                'name' => $request->name,
                'email' => $request->email,
                'password' => Hash::make($request->password),
                'role' => 'teacher',
                'mobile_number' => $request->mobile_number,
                'current_organization_id' => $request->organization_id,
                'metadata' => [
                    'status' => 'pending_approval',
                    'qualifications' => $request->qualifications,
                    'experience' => $request->experience,
                    'specialization' => $request->specialization,
                    'applied_at' => now()->toISOString(),
                ],
            ]);

            Log::info('Teacher user created', [
                'user_id' => $user->id,
                'email' => $user->email,
            ]);

            // Attach teacher to organization with 'teacher' role in pivot table
            $user->organizations()->attach($request->organization_id, [
                'role' => 'teacher',
                'status' => 'active',
                'invited_by' => null,
                'joined_at' => now(),
            ]);

            Log::info('Teacher attached to organization', [
                'user_id' => $user->id,
                'organization_id' => $request->organization_id,
            ]);

            // Create an AdminTask for approval
            $task = AdminTask::create([
                'organization_id' => $request->organization_id,
                'task_type' => 'teacher_approval',
                'title' => 'New Teacher Application: ' . $user->name,
                'description' => 'Review and approve teacher application from ' . $user->email,
                'status' => 'pending',
                'related_entity' => url('/teacher-applications'),
                'metadata' => [
                    'user_id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'mobile_number' => $user->mobile_number,
                    'qualifications' => $request->qualifications,
                    'experience' => $request->experience,
                    'specialization' => $request->specialization,
                ],
            ]);

            Log::info('AdminTask created for teacher approval', [
                'task_id' => $task->id ?? null,
                'user_id' => $user->id,
            ]);

            // Send confirmation email to teacher
            Mail::to($user->email)->send(new TeacherApplicationReceived($user->name, $user->email));

            Log::info('Teacher application received email sent', [
                'user_id' => $user->id,
                'email' => $user->email,
            ]);

            // Clear session data
            session()->forget([
                'teacher_registration_otp',
                'teacher_registration_email',
                'teacher_otp_expires_at',
                'teacher_email_verified'
            ]);

            Log::debug('Cleared teacher registration session', [
                'email' => $user->email,
            ]);

            Log::info('Teacher registration process completed', [
                'user_id' => $user->id,
            ]);

            // Return back() for Inertia to handle properly
            return back();
        } catch (\Exception $e) {
            Log::error('Error during teacher registration', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'email' => $request->input('email'),
            ]);
            throw $e;
        }
    }

    /**
     * Admin: Get pending teacher applications (for Inertia page)
     */
    public function index(Request $request)
    {
        $user = auth()->user();
        
        // Get all applications first
        $query = AdminTask::where('task_type', 'teacher_approval')
            ->where('status', 'pending')
            ->orderBy('created_at', 'desc');
        
        // Get all applications to filter by organization
        $allApplications = $query->get();
        
        // Filter by organization if super admin and organization_id provided
        if ($user->role === 'super_admin' && $request->filled('organization_id')) {
            $orgId = $request->organization_id;
            $applications = $allApplications->filter(function($task) use ($orgId) {
                $userId = $task->metadata['user_id'] ?? null;
                if (!$userId) return false;
                
                $user = User::find($userId);
                return $user && $user->current_organization_id == $orgId;
            })->values();
        } elseif ($user->role !== 'super_admin' && $user->current_organization_id) {
            // Regular admin - filter by their organization
            $applications = $allApplications->filter(function($task) use ($user) {
                $userId = $task->metadata['user_id'] ?? null;
                if (!$userId) return false;
                
                $applicantUser = User::find($userId);
                return $applicantUser && $applicantUser->current_organization_id == $user->current_organization_id;
            })->values();
        } else {
            $applications = $allApplications;
        }
        
        // Get organizations for super admin
        $organizations = null;
        if ($user->role === 'super_admin') {
            $organizations = \App\Models\Organization::orderBy('name')->get();
        }
        
        Log::info('Pending teacher applications retrieved', ['count' => $applications->count()]);
        Log::debug('Applications data', ['applications' => $applications->toArray()]);
        
        return Inertia::render('@admin/TeacherApplications/Index', [
            'applications' => $applications,
            'organizations' => $organizations,
            'filters' => $request->only('organization_id'),
        ]);
    }

    /**
     * Admin: Get pending teacher applications (API endpoint)
     */
    public function getPendingApplications()
    {
        $tasks = AdminTask::where('task_type', 'teacher_approval')
            ->where('status', 'pending')
            ->with('user')
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json($tasks);
    }

    /**
     * Admin: Approve teacher application
     */
    public function approve(Request $request, $taskId)
    {
        $task = AdminTask::findOrFail($taskId);
        
        if ($task->task_type !== 'teacher_approval') {
            return redirect()->back()->with('error', 'Invalid task type.');
        }

        $userId = $task->metadata['user_id'] ?? null;
        if (!$userId) {
            return redirect()->back()->with('error', 'User ID not found.');
        }

        $user = User::find($userId);
        if (!$user) {
            return redirect()->back()->with('error', 'User not found.');
        }

        // Update user metadata to approved
        $metadata = $user->metadata ?? [];
        $metadata['status'] = 'approved';
        $metadata['approved_at'] = now()->toISOString();
        $metadata['approved_by'] = auth()->id();
        $user->metadata = $metadata;
        $user->save();

        // Mark task as completed
        $task->status = 'completed';
        $task->completed_at = now();
        $task->save();

        // Send approval email
        Mail::to($user->email)->send(new TeacherApproved($user));

        return redirect()->back()->with('success', 'Teacher approved successfully.');
    }

    /**
     * Admin: Reject teacher application
     */
    public function reject(Request $request, $taskId)
    {
        $task = AdminTask::findOrFail($taskId);
        
        if ($task->task_type !== 'teacher_approval') {
            return redirect()->back()->with('error', 'Invalid task type.');
        }

        $userId = $task->metadata['user_id'] ?? null;
        if (!$userId) {
            return redirect()->back()->with('error', 'User ID not found.');
        }

        $user = User::find($userId);
        if (!$user) {
            return redirect()->back()->with('error', 'User not found.');
        }

        // Update user metadata to rejected
        $metadata = $user->metadata ?? [];
        $metadata['status'] = 'rejected';
        $metadata['rejected_at'] = now()->toISOString();
        $metadata['rejected_by'] = auth()->id();
        $user->metadata = $metadata;
        $user->save();

        // Mark task as cancelled
        $task->status = 'cancelled';
        $task->completed_at = now();
        $task->save();

        // Send rejection email
        Mail::to($user->email)->send(new TeacherRejected($user->name));

        return redirect()->back()->with('success', 'Teacher application rejected.');
    }
}
