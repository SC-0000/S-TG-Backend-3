<?php

namespace App\Mail;

use App\Models\Affiliate;
use App\Models\Organization;
use Illuminate\Bus\Queueable;
use Illuminate\Queue\SerializesModels;

class AffiliateMagicLink extends BrandedMailable
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
        return $this->subject('Your Login Link')
            ->view('emails.affiliate_magic_link')
            ->with($this->brandingData([
                'affiliate' => $this->affiliate,
                'magicUrl' => $this->magicUrl,
            ]));
    }
}
