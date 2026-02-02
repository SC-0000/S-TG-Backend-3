<?php

namespace App\Console\Commands;

use App\Models\Subscription;
use App\Models\User;
use Illuminate\Console\Command;

class GrantSubscription extends Command
{
    protected $signature = 'subscription:grant {email} {plan=tutor_ai_plus} {days=30}';
    protected $description = 'Give a user temporary Tutor AI Plus access';

    public function handle()
    {
       
    $user = User::whereEmail($this->argument('email'))->firstOrFail();
    $plan = Subscription::whereSlug($this->argument('plan'))->firstOrFail();

    // ─────────────────────────────────────────────────────────────
    $days = (int) $this->argument('days');   // ← CAST TO INT
    // ─────────────────────────────────────────────────────────────

    $user->subscriptions()->attach($plan->id, [
        'starts_at' => now(),
        'ends_at'   => $days > 0 ? now()->addDays($days) : null, // 0 = indefinite
        'status'    => 'active',
        'source'    => 'manual',
    ]);

    $this->info("✅ {$user->email} now has {$plan->name} for {$days} days");
    }
}
