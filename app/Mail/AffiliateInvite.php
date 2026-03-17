<?php

namespace App\Mail;

use App\Models\Affiliate;
use App\Models\Organization;
use Illuminate\Bus\Queueable;
use Illuminate\Queue\SerializesModels;

class AffiliateInvite extends BrandedMailable
{
    use Queueable, SerializesModels;

    public Affiliate $affiliate;
    public string $magicUrl;

    public function __construct(Affiliate $affiliate, string $magicUrl, ?Organization $organization = null)
    {
        $this->affiliate = $affiliate;
        $this->magicUrl = $magicUrl;
        $this->organization = $organization;
    }

    public function build()
    {
        $brandName = $this->organization?->getSetting('branding.organization_name') ?? config('app.name');

        return $this->subject("You've been invited to join {$brandName} as an affiliate")
            ->view('emails.affiliate_invite')
            ->with($this->brandingData([
                'affiliate' => $this->affiliate,
                'magicUrl' => $this->magicUrl,
            ]));
    }
}
