<?php

namespace App\Mail;

use App\Models\Affiliate;
use App\Models\AffiliatePayout;
use App\Models\Organization;
use Illuminate\Bus\Queueable;
use Illuminate\Queue\SerializesModels;

class AffiliatePayoutNotification extends BrandedMailable
{
    use Queueable, SerializesModels;

    public Affiliate $affiliate;
    public AffiliatePayout $payout;

    public function __construct(Affiliate $affiliate, AffiliatePayout $payout, ?Organization $organization = null)
    {
        $this->affiliate = $affiliate;
        $this->payout = $payout;
        $this->organization = $organization;
    }

    public function build()
    {
        return $this->subject('Your Payout Has Been Processed')
            ->view('emails.affiliate_payout')
            ->with($this->brandingData([
                'affiliate' => $this->affiliate,
                'payout' => $this->payout,
            ]));
    }
}
