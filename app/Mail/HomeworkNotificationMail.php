<?php

namespace App\Mail;

use App\Models\Organization;
use Illuminate\Bus\Queueable;
use Illuminate\Queue\SerializesModels;

class HomeworkNotificationMail extends BrandedMailable
{
    use Queueable, SerializesModels;

    public string $titleText;
    public string $messageText;
    public ?string $path;
    public ?string $actionLabel;
    public ?string $childName;

    public function __construct(
        string $titleText,
        string $messageText,
        ?string $path = null,
        ?Organization $organization = null,
        ?string $actionLabel = null,
        ?string $childName = null
    ) {
        $this->titleText = $titleText;
        $this->messageText = $messageText;
        $this->path = $path;
        $this->actionLabel = $actionLabel;
        $this->childName = $childName;
        $this->organization = $organization ?? $this->resolveOrganization();
    }

    public function build()
    {
        $actionUrl = $this->path ? $this->portalUrl($this->path, $this->organization) : null;

        return $this->subject($this->titleText)
            ->view('emails.homework-notification')
            ->with($this->brandingData([
                'titleText' => $this->titleText,
                'messageText' => $this->messageText,
                'actionUrl' => $actionUrl,
                'actionLabel' => $this->actionLabel ?? 'View Homework',
                'childName' => $this->childName,
            ]));
    }
}
