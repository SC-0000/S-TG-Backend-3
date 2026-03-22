<?php

namespace App\Services;

use App\Models\Application;
use App\Models\ApplicationActivity;
use App\Models\CommunicationMessage;

class ApplicationActivityService
{
    /**
     * Log an activity against an application.
     */
    public static function log(
        Application $application,
        string      $type,
        string      $title,
        ?string     $description = null,
        ?int        $userId = null,
        array       $metadata = []
    ): ApplicationActivity {
        return ApplicationActivity::create([
            'application_id'  => $application->application_id,
            'organization_id' => $application->organization_id,
            'user_id'         => $userId,
            'activity_type'   => $type,
            'title'           => $title,
            'description'     => $description,
            'metadata'        => !empty($metadata) ? $metadata : null,
        ]);
    }

    /**
     * Log a pipeline status change.
     */
    public static function logStatusChange(
        Application $application,
        string      $from,
        string      $to,
        ?int        $userId = null
    ): ApplicationActivity {
        return self::log(
            $application,
            ApplicationActivity::TYPE_STATUS_CHANGE,
            "Status changed from {$from} to {$to}",
            null,
            $userId,
            ['from' => $from, 'to' => $to]
        );
    }

    /**
     * Log an admin note.
     */
    public static function logNote(
        Application $application,
        string      $note,
        int         $userId
    ): ApplicationActivity {
        return self::log(
            $application,
            ApplicationActivity::TYPE_NOTE,
            'Note added',
            $note,
            $userId
        );
    }

    /**
     * Log a communication (email, SMS, WhatsApp) sent from the application context.
     */
    public static function logCommunication(
        Application          $application,
        string               $channel,
        ?CommunicationMessage $message = null,
        ?int                  $userId = null,
        ?string               $summary = null
    ): ApplicationActivity {
        $metadata = [];
        if ($message) {
            $metadata['message_id'] = $message->id;
            $metadata['channel']    = $message->channel;
            $metadata['status']     = $message->status;
        }

        return self::log(
            $application,
            $channel, // 'email', 'sms', 'whatsapp'
            $summary ?? ucfirst($channel) . ' sent',
            $message?->body_text ? mb_substr($message->body_text, 0, 200) : null,
            $userId,
            $metadata
        );
    }

    /**
     * Log a system event (automated actions, verifications, etc.).
     */
    public static function logSystemEvent(
        Application $application,
        string      $title,
        ?string     $description = null,
        array       $metadata = []
    ): ApplicationActivity {
        return self::log(
            $application,
            ApplicationActivity::TYPE_SYSTEM,
            $title,
            $description,
            null,
            $metadata
        );
    }

    /**
     * Log a call.
     */
    public static function logCall(
        Application $application,
        int         $userId,
        ?string     $notes = null,
        array       $metadata = []
    ): ApplicationActivity {
        return self::log(
            $application,
            ApplicationActivity::TYPE_CALL,
            'Call logged',
            $notes,
            $userId,
            $metadata
        );
    }
}
