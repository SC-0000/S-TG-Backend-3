<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Api\ApiController;
use App\Jobs\SendEmailCampaignBatchJob;
use App\Mail\EmailCampaignMail;
use App\Models\NewsletterCampaign;
use App\Models\NewsletterCampaignRecipient;
use App\Models\NewsletterSubscriber;
use App\Models\Organization;
use App\Models\User;
use App\Support\ApiPagination;
use App\Support\MailContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class EmailCampaignController extends ApiController
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! $user) {
            return $this->error('Unauthenticated.', [], 401);
        }

        $orgId = $request->attributes->get('organization_id') ?: $user->current_organization_id;
        $query = NewsletterCampaign::query()
            ->when($orgId, fn ($q) => $q->where('organization_id', $orgId))
            ->withCount([
                'recipients as queued_recipients_count' => fn ($q) => $q->where('status', 'queued'),
            ])
            ->latest();

        $campaigns = $query->paginate(ApiPagination::perPage($request, 20));
        $campaigns->getCollection()->each(function (NewsletterCampaign $campaign) {
            $queuedCount = (int) ($campaign->queued_recipients_count ?? 0);
            $this->normalizeCampaignStatus($campaign, $queuedCount);
        });
        $data = $campaigns->getCollection()->map(fn (NewsletterCampaign $campaign) => $this->mapCampaign($campaign))->all();

        return $this->paginated($campaigns, $data);
    }

    public function show(Request $request, NewsletterCampaign $campaign): JsonResponse
    {
        if ($response = $this->ensureOrgScope($request, $campaign)) {
            return $response;
        }

        $queuedCount = $campaign->recipients()->where('status', 'queued')->count();
        $this->normalizeCampaignStatus($campaign, $queuedCount);

        return $this->success($this->mapCampaign($campaign));
    }

    public function store(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! $user) {
            return $this->error('Unauthenticated.', [], 401);
        }

        $payload = $this->validatePayload($request);
        $orgId = $request->attributes->get('organization_id') ?: $user->current_organization_id;

        if (! $orgId) {
            return $this->error('Organization is required.', [], 422);
        }

        $campaign = NewsletterCampaign::create(array_merge($payload, [
            'organization_id' => $orgId,
            'created_by' => $user->id,
            'updated_by' => $user->id,
            'status' => 'draft',
        ]));

        return $this->success($this->mapCampaign($campaign), [], 201);
    }

    public function update(Request $request, NewsletterCampaign $campaign): JsonResponse
    {
        if ($response = $this->ensureOrgScope($request, $campaign)) {
            return $response;
        }

        $payload = $this->validatePayload($request);
        $user = $request->user();

        $campaign->fill($payload);
        $campaign->updated_by = $user?->id;
        $campaign->save();

        return $this->success($this->mapCampaign($campaign));
    }

    public function send(Request $request, NewsletterCampaign $campaign): JsonResponse
    {
        if ($response = $this->ensureOrgScope($request, $campaign)) {
            return $response;
        }

        if ($campaign->status === 'sending') {
            return $this->error('Campaign is already sending.', [], 409);
        }

        $organization = Organization::find($campaign->organization_id);
        if (! $organization) {
            return $this->error('Organization not found.', [], 422);
        }

        $filters = $campaign->filters ?? [];
        $roles = $filters['roles'] ?? [];
        $userIds = $filters['user_ids'] ?? [];
        $subscriberIds = $filters['subscriber_ids'] ?? [];
        $includeUsers = $filters['include_users'] ?? true;
        $includeSubscribers = $filters['include_subscribers'] ?? true;

        DB::transaction(function () use ($campaign, $organization, $roles, $userIds, $subscriberIds, $includeUsers, $includeSubscribers) {
            NewsletterCampaignRecipient::where('campaign_id', $campaign->id)->delete();

            $rows = [];
            $now = now();

            if ($includeUsers || (is_array($userIds) && count($userIds) > 0)) {
                $userQuery = User::query()
                    ->where('current_organization_id', $campaign->organization_id)
                    ->whereNotNull('email');

                if (is_array($roles) && count($roles) > 0) {
                    $userQuery->whereIn('role', $roles);
                }

                if (is_array($userIds) && count($userIds) > 0) {
                    $userQuery->whereIn('id', $userIds);
                }

                $userQuery->chunk(500, function ($users) use (&$rows, $campaign, $now) {
                    foreach ($users as $user) {
                        $rows[] = [
                            'campaign_id' => $campaign->id,
                            'recipient_type' => 'user',
                            'recipient_id' => $user->id,
                            'email' => $user->email,
                            'name' => $user->name,
                            'status' => 'queued',
                            'created_at' => $now,
                            'updated_at' => $now,
                        ];
                    }
                });
            }

            if ($includeSubscribers || (is_array($subscriberIds) && count($subscriberIds) > 0)) {
                NewsletterSubscriber::query()
                    ->where('organization_id', $campaign->organization_id)
                    ->where('status', 'active')
                    ->whereNotNull('email')
                    ->when(is_array($subscriberIds) && count($subscriberIds) > 0, function ($q) use ($subscriberIds) {
                        $q->whereIn('id', $subscriberIds);
                    })
                    ->chunk(500, function ($subscribers) use (&$rows, $campaign, $now) {
                        foreach ($subscribers as $subscriber) {
                            $rows[] = [
                                'campaign_id' => $campaign->id,
                                'recipient_type' => 'subscriber',
                                'recipient_id' => $subscriber->id,
                                'email' => $subscriber->email,
                                'name' => $subscriber->name,
                                'status' => 'queued',
                                'created_at' => $now,
                                'updated_at' => $now,
                            ];
                        }
                    });
            }

            foreach (array_chunk($rows, 500) as $chunk) {
                NewsletterCampaignRecipient::insert($chunk);
            }

            $campaign->status = 'sending';
            $campaign->sent_at = null;
            $campaign->sent_count = 0;
            $campaign->save();
        });

        $recipientIds = NewsletterCampaignRecipient::where('campaign_id', $campaign->id)
            ->where('status', 'queued')
            ->pluck('id')
            ->all();

        foreach (array_chunk($recipientIds, 200) as $chunk) {
            SendEmailCampaignBatchJob::dispatch($campaign->id, $chunk);
        }

        return $this->success(['message' => 'Campaign queued for sending.']);
    }

    public function test(Request $request, NewsletterCampaign $campaign): JsonResponse
    {
        if ($response = $this->ensureOrgScope($request, $campaign)) {
            return $response;
        }

        $payload = $request->validate([
            'email' => ['required', 'email', 'max:255'],
            'name' => ['nullable', 'string', 'max:255'],
        ]);

        $organization = Organization::find($campaign->organization_id);

        $unsubscribeUrl = null;
        $merged = $this->applyMergeTags($campaign, [
            'name' => $payload['name'] ?? 'there',
            'email' => $payload['email'],
            'unsubscribe_link' => $unsubscribeUrl,
            'org_name' => $organization?->getSetting('branding.organization_name') ?? config('app.name'),
            'date' => now()->toFormattedDateString(),
        ]);

        MailContext::sendMailable(
            $payload['email'],
            new EmailCampaignMail($campaign, $organization, $merged['html'], $merged['text'], $unsubscribeUrl, $payload['name'] ?? null)
        );

        return $this->success(['message' => 'Test email sent.']);
    }

    private function validatePayload(Request $request): array
    {
        $payload = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'subject' => ['required', 'string', 'max:255'],
            'content_html' => ['required', 'string'],
            'content_text' => ['nullable', 'string'],
            'template_key' => ['nullable', 'string', 'max:100'],
            'filters' => ['nullable', 'array'],
            'filters.user_ids' => ['nullable', 'array'],
            'filters.user_ids.*' => ['integer'],
            'filters.subscriber_ids' => ['nullable', 'array'],
            'filters.subscriber_ids.*' => ['integer'],
        ]);

        if (empty($payload['content_text'])) {
            $payload['content_text'] = trim(strip_tags($payload['content_html']));
        }

        if (empty($payload['template_key'])) {
            $payload['template_key'] = 'clean';
        }

        return $payload;
    }

    private function mapCampaign(NewsletterCampaign $campaign): array
    {
        return [
            'id' => $campaign->id,
            'title' => $campaign->title,
            'subject' => $campaign->subject,
            'content_html' => $campaign->content_html,
            'content_text' => $campaign->content_text,
            'template_key' => $campaign->template_key,
            'filters' => $campaign->filters ?? [],
            'status' => $campaign->status,
            'scheduled_at' => $campaign->scheduled_at,
            'sent_at' => $campaign->sent_at,
            'sent_count' => $campaign->sent_count,
            'open_count' => $campaign->open_count,
            'click_count' => $campaign->click_count,
            'bounce_count' => $campaign->bounce_count,
            'queued_recipients_count' => $campaign->queued_recipients_count ?? null,
            'created_at' => $campaign->created_at,
            'updated_at' => $campaign->updated_at,
        ];
    }

    private function normalizeCampaignStatus(NewsletterCampaign $campaign, int $queuedCount): void
    {
        if ($campaign->status !== 'sending' || $queuedCount > 0) {
            return;
        }

        $sentAt = $campaign->recipients()
            ->whereNotNull('sent_at')
            ->latest('sent_at')
            ->value('sent_at');

        $campaign->status = 'sent';
        $campaign->sent_at = $campaign->sent_at ?? $sentAt ?? now();
        $campaign->save();
    }

    private function applyMergeTags(NewsletterCampaign $campaign, array $data): array
    {
        $replacements = [
            '{{name}}' => $data['name'] ?? '',
            '{{email}}' => $data['email'] ?? '',
            '{{unsubscribe_link}}' => $data['unsubscribe_link'] ?? '',
            '{{org_name}}' => $data['org_name'] ?? '',
            '{{date}}' => $data['date'] ?? '',
        ];

        $html = str_replace(array_keys($replacements), array_values($replacements), $campaign->content_html);
        $text = $campaign->content_text
            ? str_replace(array_keys($replacements), array_values($replacements), $campaign->content_text)
            : trim(strip_tags($html));

        return ['html' => $html, 'text' => $text];
    }

    private function ensureOrgScope(Request $request, NewsletterCampaign $campaign): ?JsonResponse
    {
        $user = $request->user();
        if (! $user) {
            return $this->error('Unauthenticated.', [], 401);
        }

        $orgId = $request->attributes->get('organization_id') ?: $user->current_organization_id;
        if ($campaign->organization_id !== $orgId) {
            return $this->error('Unauthorized access.', [], 403);
        }

        return null;
    }
}
