<?php

namespace App\Jobs;

use App\DTOs\SendMessageDTO;
use App\Models\Organization;
use App\Models\User;
use App\Services\Communications\ChannelDispatcher;
use App\Services\Communications\DigestService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class SendDigestEmailsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;
    public int $timeout = 300;

    public function __construct(
        protected string $digestType = 'daily', // 'daily' or 'weekly'
    ) {}

    public function handle(DigestService $digestService, ChannelDispatcher $dispatcher): void
    {
        $organizations = Organization::active()->get();

        foreach ($organizations as $org) {
            try {
                $this->sendDigestsForOrg($org, $digestService, $dispatcher);
            } catch (\Throwable $e) {
                Log::error("[DigestEmail] Failed for org {$org->id}", ['error' => $e->getMessage()]);
            }
        }
    }

    protected function sendDigestsForOrg(
        Organization $org,
        DigestService $digestService,
        ChannelDispatcher $dispatcher,
    ): void {
        // Get admin and teacher users for this org
        $users = $org->users()
            ->wherePivotIn('role', ['org_admin', 'teacher'])
            ->wherePivot('status', 'active')
            ->get();

        foreach ($users as $user) {
            $digest = $this->digestType === 'weekly'
                ? $digestService->generateWeeklyDigest($user, $org)
                : $digestService->generateDailyDigest($user, $org);

            $bodyText = $digestService->formatAsText($digest, $org);
            $period = $this->digestType === 'weekly' ? 'Weekly' : 'Daily';
            $orgName = $org->getSetting('branding.organization_name') ?? $org->name;

            $dto = SendMessageDTO::email(
                bodyText: $bodyText,
                subject: "{$period} Summary - {$orgName}",
                recipientUserId: $user->id,
                recipientAddress: $user->email,
                senderType: 'system',
                metadata: ['type' => 'digest', 'digest_type' => $this->digestType],
            );

            $dispatcher->send($org, $dto);
        }
    }
}
