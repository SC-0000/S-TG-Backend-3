<?php

namespace App\Http\Controllers\Api\Admin;

use App\DTOs\SendMessageDTO;
use App\Http\Controllers\Api\ApiController;
use App\Models\Application;
use App\Models\CallLog;
use App\Models\Organization;
use App\Services\ApplicationActivityService;
use App\Services\Communications\ChannelDispatcher;
use App\Services\Tasks\TaskService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class ApplicationContactController extends ApiController
{
    protected ChannelDispatcher $dispatcher;

    public function __construct(ChannelDispatcher $dispatcher)
    {
        $this->dispatcher = $dispatcher;
    }

    /**
     * Send a message (email/SMS/WhatsApp) to the applicant.
     */
    public function sendMessage(Request $request, Application $application): JsonResponse
    {
        $validated = $request->validate([
            'channel'   => 'required|in:email,sms,whatsapp',
            'body_text' => 'required|string|max:5000',
            'subject'   => 'required_if:channel,email|nullable|string|max:255',
        ]);

        $orgId = $application->organization_id;

        // Resolve the recipient address based on channel
        $address = match ($validated['channel']) {
            'email'    => $application->email,
            'sms'      => $application->mobile_number ?? $application->phone_number,
            'whatsapp' => $application->mobile_number ?? $application->phone_number,
        };

        if (!$address) {
            return $this->error('No contact address available for this channel.', [], 422);
        }

        try {
            $org = Organization::findOrFail($orgId);

            $dto = new SendMessageDTO(
                channel:          $validated['channel'],
                bodyText:         $validated['body_text'],
                recipientAddress: $address,
                subject:          $validated['subject'] ?? null,
                senderType:       'admin',
                senderId:         $request->user()->id,
            );

            $message = $this->dispatcher->send($org, $dto);

            ApplicationActivityService::logCommunication(
                $application,
                $validated['channel'],
                $message,
                $request->user()->id,
                ucfirst($validated['channel']) . ' sent to ' . $address
            );

            return $this->success(['message' => 'Message sent successfully.']);
        } catch (\Throwable $e) {
            Log::error('Failed to send message from application', [
                'application_id' => $application->application_id,
                'channel'        => $validated['channel'],
                'error'          => $e->getMessage(),
            ]);

            return $this->error('Failed to send message: ' . $e->getMessage(), [], 500);
        }
    }

    /**
     * Log a phone call against the application.
     */
    public function logCall(Request $request, Application $application): JsonResponse
    {
        $validated = $request->validate([
            'notes'            => 'nullable|string|max:5000',
            'duration_seconds' => 'nullable|integer|min:0',
            'outcome'          => 'nullable|string|in:reached,voicemail,no_answer,busy',
        ]);

        $orgId = $application->organization_id;

        // Create a CallLog entry if the model exists and table is available
        try {
            $callLog = CallLog::create([
                'organization_id'   => $orgId,
                'from_number'       => null,
                'to_number'         => $application->mobile_number ?? $application->phone_number,
                'direction'         => 'outbound',
                'initiated_by'      => $request->user()->id,
                'status'            => 'completed',
                'duration_seconds'  => $validated['duration_seconds'] ?? 0,
                'transcription'     => $validated['notes'],
                'metadata'          => ['outcome' => $validated['outcome'] ?? 'reached', 'application_id' => $application->application_id],
                'started_at'        => now(),
                'ended_at'          => now(),
            ]);
        } catch (\Throwable $e) {
            Log::warning('Could not create CallLog', ['error' => $e->getMessage()]);
            $callLog = null;
        }

        ApplicationActivityService::logCall(
            $application,
            $request->user()->id,
            $validated['notes'],
            [
                'duration_seconds' => $validated['duration_seconds'] ?? null,
                'outcome'          => $validated['outcome'] ?? 'reached',
                'call_log_id'      => $callLog?->id,
            ]
        );

        // Auto-update pipeline status to contacted if currently new/verified
        if (in_array($application->pipeline_status, [Application::PIPELINE_NEW, Application::PIPELINE_VERIFIED])) {
            $application->update([
                'pipeline_status'            => Application::PIPELINE_CONTACTED,
                'pipeline_status_changed_at' => now(),
            ]);
        }

        return $this->success(['message' => 'Call logged successfully.']);
    }

    /**
     * Schedule a follow-up task for the application.
     */
    public function scheduleFollowUp(Request $request, Application $application): JsonResponse
    {
        $validated = $request->validate([
            'due_at'      => 'required|date|after:now',
            'description' => 'nullable|string|max:2000',
        ]);

        TaskService::createFromEvent('application_follow_up', [
            'source_model'   => $application,
            'title'          => 'Follow up: ' . $application->applicant_name,
            'description'    => $validated['description'] ?? 'Follow up with applicant',
            'due_at'         => $validated['due_at'],
            'assigned_to'    => $request->user()->id,
        ]);

        // Update pipeline status
        if (!in_array($application->pipeline_status, [Application::PIPELINE_APPROVED, Application::PIPELINE_REJECTED])) {
            $application->update([
                'pipeline_status'            => Application::PIPELINE_FOLLOW_UP,
                'pipeline_status_changed_at' => now(),
            ]);
        }

        ApplicationActivityService::logSystemEvent(
            $application,
            'Follow-up scheduled',
            $validated['description'] ?? 'Follow up scheduled for ' . $validated['due_at'],
            ['due_at' => $validated['due_at'], 'assigned_to' => $request->user()->id]
        );

        return $this->success(['message' => 'Follow-up scheduled.']);
    }
}
