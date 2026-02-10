<?php

namespace App\Mail;

use App\Models\Organization;
use App\Models\Transaction;
use Illuminate\Bus\Queueable;
use Illuminate\Queue\SerializesModels;

class ReceiptAccessMail extends BrandedMailable
{
    use Queueable, SerializesModels;

    public Transaction $transaction;
    public string $messageType; // e.g., 'receipt' or 'access_granted'

    /**
     * Create a new message instance.
     *
     * @param Transaction $transaction
     * @param string $messageType
     * @param Organization|null $organization
     */
    public function __construct(Transaction $transaction, string $messageType = 'receipt', ?Organization $organization = null)
    {
        $this->transaction = $transaction;
        $this->messageType = $messageType;
        $this->organization = $organization ?? $this->resolveOrganizationFromTransaction($transaction);
    }

    /**
     * Build the message.
     *
     * @return $this
     */
    public function build()
    {
        $subject = $this->messageType === 'access_granted'
            ? "Access granted for your recent purchase (##{$this->transaction->id})"
            : "Receipt for your purchase (##{$this->transaction->id})";

        return $this->subject($subject)
                    ->view('emails.receipt_access')
                    ->with($this->brandingData([
                        'transaction' => $this->transaction,
                        'messageType' => $this->messageType,
                    ]));
    }

    protected function resolveOrganizationFromTransaction(Transaction $transaction): ?Organization
    {
        try {
            $item = $transaction->items()->with('item')->get()->pluck('item')->filter()->first();
            if ($item && isset($item->organization_id) && $item->organization_id) {
                return Organization::find($item->organization_id);
            }
        } catch (\Throwable $e) {
            // Ignore and fall back.
        }

        if ($transaction->user) {
            $resolved = $this->resolveOrganization(null, $transaction->user);
            if ($resolved) {
                return $resolved;
            }
        }

        return $this->resolveOrganization();
    }
}
