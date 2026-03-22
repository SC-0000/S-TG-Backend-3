<?php

namespace App\Services\Communications\Drivers;

use App\DTOs\SendMessageDTO;
use App\Mail\CommunicationMail;
use App\Models\CommunicationMessage;
use App\Models\Organization;
use App\Support\MailContext;

class EmailDriver
{
    /**
     * Send an email via the existing BrandedMailable + MailContext infrastructure.
     *
     * @return string|null External message ID from the mail provider
     */
    public function send(Organization $org, CommunicationMessage $message, SendMessageDTO $dto): ?string
    {
        $recipientEmail = $message->recipient_address;
        if (!$recipientEmail) {
            throw new \RuntimeException('No email address for recipient');
        }

        $mailable = new CommunicationMail(
            subject: $dto->subject ?? 'Notification',
            bodyText: $dto->bodyText,
            bodyHtml: $dto->bodyHtml,
        );
        $mailable->setOrganization($org);

        MailContext::sendMailable($recipientEmail, $mailable);

        return null; // Mail providers return IDs asynchronously via webhooks
    }
}
