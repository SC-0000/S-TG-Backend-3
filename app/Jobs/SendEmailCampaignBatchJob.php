<?php

namespace App\Jobs;

use App\Mail\EmailCampaignMail;
use App\Models\NewsletterCampaign;
use App\Models\NewsletterCampaignRecipient;
use App\Models\NewsletterSubscriber;
use App\Models\Organization;
use App\Support\MailContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class SendEmailCampaignBatchJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public int $campaignId,
        public array $recipientIds
    ) {}

    public function handle(): void
    {
        $campaign = NewsletterCampaign::find($this->campaignId);
        if (! $campaign) {
            return;
        }

        $organization = Organization::find($campaign->organization_id);
        $recipients = NewsletterCampaignRecipient::whereIn('id', $this->recipientIds)
            ->where('campaign_id', $campaign->id)
            ->get();

        $sentCount = 0;

        foreach ($recipients as $recipient) {
            if (! $recipient->email) {
                $recipient->status = 'failed';
                $recipient->failed_reason = 'Missing email address';
                $recipient->save();
                continue;
            }

            $unsubscribeUrl = null;
            if ($recipient->recipient_type === 'subscriber' && $recipient->recipient_id) {
                $subscriber = NewsletterSubscriber::find($recipient->recipient_id);
                if ($subscriber) {
                    $unsubscribeUrl = $this->buildUnsubscribeUrl($organization, $subscriber->unsubscribe_token);
                }
            }

            $merged = $this->applyMergeTags($campaign, [
                'name' => $recipient->name ?: 'there',
                'email' => $recipient->email,
                'unsubscribe_link' => $unsubscribeUrl,
                'org_name' => $organization?->getSetting('branding.organization_name') ?? config('app.name'),
                'date' => now()->toFormattedDateString(),
            ]);

            try {
                MailContext::sendMailable(
                    $recipient->email,
                    new EmailCampaignMail($campaign, $organization, $merged['html'], $merged['text'], $unsubscribeUrl, $recipient->name),
                    false
                );
                $recipient->status = 'sent';
                $recipient->sent_at = now();
                $recipient->failed_reason = null;
                $recipient->save();
                $sentCount++;
            } catch (\Throwable $e) {
                $recipient->status = 'failed';
                $recipient->failed_reason = $e->getMessage();
                $recipient->save();
            }
        }

        if ($sentCount > 0) {
            $campaign->increment('sent_count', $sentCount);
        }

        $remaining = NewsletterCampaignRecipient::where('campaign_id', $campaign->id)
            ->where('status', 'queued')
            ->count();

        if ($remaining === 0) {
            $campaign->status = 'sent';
            $campaign->sent_at = now();
            $campaign->save();
        }
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

    private function buildUnsubscribeUrl(?Organization $organization, ?string $token): ?string
    {
        if (! $token) {
            return null;
        }

        $base = $organization?->public_domain ?: $organization?->portal_domain ?: config('app.frontend_url');
        if (! $base || ! is_string($base)) {
            return null;
        }

        $base = trim($base);
        if ($base === '') {
            return null;
        }

        if (! preg_match('#^https?://#i', $base)) {
            $host = preg_replace('#/.*$#', '', $base);
            $isLocal = str_starts_with($host, 'localhost') || str_starts_with($host, '127.0.0.1');
            $base = ($isLocal ? 'http://' : 'https://') . $host;
        }

        return rtrim($base, '/') . '/newsletter/unsubscribe/' . $token;
    }
}
