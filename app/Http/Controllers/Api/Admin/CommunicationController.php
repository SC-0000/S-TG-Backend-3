<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Api\ApiController;
use App\Jobs\SendEmailCampaignBatchJob;
use App\Mail\EmailCampaignMail;
use App\Models\AppNotification;
use App\Models\NewsletterCampaign;
use App\Models\NewsletterCampaignRecipient;
use App\Models\Organization;
use App\Support\MailContext;
use App\Services\Communications\RecipientResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CommunicationController extends ApiController
{
    public function send(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! $user) {
            return $this->error('Unauthenticated.', [], 401);
        }

        $payload = $request->validate([
            'channels' => 'required|array',
            'channels.email' => 'boolean',
            'channels.notification' => 'boolean',
            'recipients' => 'required|array',
            'recipients.roles' => 'array',
            'recipients.roles.*' => 'string',
            'recipients.include_users' => 'boolean',
            'recipients.include_subscribers' => 'boolean',
            'recipients.user_ids' => 'array',
            'recipients.user_ids.*' => 'integer',
            'recipients.subscriber_ids' => 'array',
            'recipients.subscriber_ids.*' => 'integer',
            'recipients.parent_id' => 'nullable|integer',
            'recipients.child_id' => 'nullable|integer',
            'recipients.search' => 'nullable|string',
            'email' => 'array',
            'email.subject' => 'nullable|string|max:255',
            'email.content_html' => 'nullable|string',
            'email.content_text' => 'nullable|string',
            'email.template_key' => 'nullable|string|max:255',
            'notification' => 'array',
            'notification.title' => 'nullable|string|max:255',
            'notification.message' => 'nullable|string',
            'notification.type' => 'nullable|in:lesson,assessment,payment,task',
            'notification.status' => 'nullable|in:unread,read',
        ]);

        $channels = $payload['channels'] ?? [];
        $sendEmail = (bool) ($channels['email'] ?? false);
        $sendNotification = (bool) ($channels['notification'] ?? false);

        if (! $sendEmail && ! $sendNotification) {
            return $this->error('Select at least one channel.', [], 422);
        }

        $orgId = $request->attributes->get('organization_id') ?: $user->current_organization_id;
        if (! $orgId) {
            return $this->error('Organization is required.', [], 422);
        }

        $resolver = new RecipientResolver($orgId, $payload['recipients'] ?? []);
        $resolved = $resolver->resolve();
        $users = $resolved['users'];
        $subscribers = $resolved['subscribers'];
        $notificationParents = $resolved['notification_parents'];

        $stats = [
            'email_recipients' => 0,
            'notification_recipients' => 0,
            'notifications_created' => 0,
        ];

        $subject = null;

        if ($sendEmail) {
            $emailData = $payload['email'] ?? [];
            $subject = trim((string) ($emailData['subject'] ?? ''));
            $contentHtml = (string) ($emailData['content_html'] ?? '');
            $contentText = (string) ($emailData['content_text'] ?? '');
            $templateKey = $emailData['template_key'] ?? 'clean';

            if ($subject === '' && $sendNotification) {
                $subject = (string) ($payload['notification']['title'] ?? 'Notification');
            }

            if ($contentHtml === '' && $sendNotification) {
                $contentHtml = '<p>' . e((string) ($payload['notification']['message'] ?? '')) . '</p>';
            }

            if ($contentText === '') {
                $contentText = trim(strip_tags($contentHtml));
            }

            $campaign = NewsletterCampaign::create([
                'organization_id' => $orgId,
                'title' => $subject ?: 'Campaign',
                'subject' => $subject ?: 'Campaign',
                'content_html' => $contentHtml,
                'content_text' => $contentText,
                'template_key' => $templateKey,
                'status' => 'sending',
                'created_by' => $user->id,
                'updated_by' => $user->id,
                'filters' => $payload['recipients'] ?? [],
            ]);

            $rows = [];
            $now = now();

            foreach ($users as $u) {
                if (! $u->email) continue;
                $rows[] = [
                    'campaign_id' => $campaign->id,
                    'recipient_type' => 'user',
                    'recipient_id' => $u->id,
                    'email' => $u->email,
                    'name' => $u->name,
                    'status' => 'queued',
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }

            foreach ($subscribers as $s) {
                if (! $s->email) continue;
                $rows[] = [
                    'campaign_id' => $campaign->id,
                    'recipient_type' => 'subscriber',
                    'recipient_id' => $s->id,
                    'email' => $s->email,
                    'name' => $s->name,
                    'status' => 'queued',
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }

            foreach (array_chunk($rows, 500) as $chunk) {
                NewsletterCampaignRecipient::insert($chunk);
            }

            $recipientIds = NewsletterCampaignRecipient::where('campaign_id', $campaign->id)
                ->where('status', 'queued')
                ->pluck('id')
                ->all();

            foreach (array_chunk($recipientIds, 200) as $chunk) {
                SendEmailCampaignBatchJob::dispatch($campaign->id, $chunk);
            }

            $stats['email_recipients'] = count($rows);
        }

        if ($sendNotification) {
            $notification = $payload['notification'] ?? [];
            $title = trim((string) ($notification['title'] ?? $subject ?? 'Notification'));
            $message = trim((string) ($notification['message'] ?? ''));
            $type = $notification['type'] ?? 'task';
            $status = $notification['status'] ?? 'unread';

            if ($message === '' && $sendEmail) {
                $message = trim(strip_tags((string) ($payload['email']['content_html'] ?? '')));
            }

            $count = 0;
            foreach ($notificationParents as $parent) {
                foreach ($parent->children as $child) {
                    AppNotification::create([
                        'user_id' => $parent->id,
                        'child_id' => $child->id,
                        'title' => $title,
                        'message' => "For “{$child->child_name}”: {$message}",
                        'type' => $type,
                        'status' => $status,
                        'channel' => 'in-app',
                    ]);
                    $count++;
                }
            }

            $stats['notification_recipients'] = $notificationParents->count();
            $stats['notifications_created'] = $count;
        }

        return $this->success(['message' => 'Communication sent.', 'stats' => $stats]);
    }
}
