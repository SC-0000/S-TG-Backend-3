<?php

namespace App\Mail;

use App\Models\Organization;
use App\Models\Transaction;
use Illuminate\Bus\Queueable;
use Illuminate\Queue\SerializesModels;

class PaymentFailedNotification extends BrandedMailable
{
    use Queueable, SerializesModels;

    public Transaction $transaction;
    public array $failureData;

    public function __construct(Transaction $transaction, array $failureData = [], ?Organization $organization = null)
    {
        $this->transaction = $transaction;
        $this->failureData = $failureData;
        $this->organization = $organization;
    }

    public function build()
    {
        $failureCode    = $this->failureData['failure_code'] ?? null;
        $failureMessage = $this->failureData['failure_message'] ?? null;

        // Provide user-friendly message based on failure code
        $userMessage = match ($failureCode) {
            'card_declined'          => 'Your card was declined. Please try a different payment method.',
            'insufficient_funds'     => 'Your card has insufficient funds. Please ensure funds are available and try again.',
            'authentication_required', 'authentication_failed'
                                     => 'Card authentication failed. Please try again — your bank may require additional verification.',
            'expired_card'           => 'Your card has expired. Please update your payment method.',
            default                  => $failureMessage ?? 'Your payment could not be processed. Please try again or use a different payment method.',
        };

        return $this->subject("Payment failed for transaction #{$this->transaction->id}")
            ->view('emails.payment_failed')
            ->with($this->brandingData([
                'transaction'    => $this->transaction,
                'userMessage'    => $userMessage,
                'failureCode'    => $failureCode,
                'amount'         => number_format($this->transaction->total, 2),
            ]));
    }
}
