<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;
use App\Models\Transaction;

class ReceiptAccessMail extends Mailable
{
    use Queueable, SerializesModels;

    public Transaction $transaction;
    public string $messageType; // e.g., 'receipt' or 'access_granted'

    /**
     * Create a new message instance.
     *
     * @param Transaction $transaction
     * @param string $messageType
     */
    public function __construct(Transaction $transaction, string $messageType = 'receipt')
    {
        $this->transaction = $transaction;
        $this->messageType = $messageType;
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
                    ->with([
                        'transaction' => $this->transaction,
                        'messageType' => $this->messageType,
                    ]);
    }
}
