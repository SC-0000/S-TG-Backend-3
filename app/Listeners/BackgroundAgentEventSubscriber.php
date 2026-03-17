<?php

namespace App\Listeners;

use App\Events\AssessmentSubmitted;
use App\Events\ContentUpdated;
use App\Services\AI\BackgroundAgents\BackgroundAgentOrchestrator;
use Illuminate\Events\Dispatcher;

class BackgroundAgentEventSubscriber
{
    protected BackgroundAgentOrchestrator $orchestrator;

    public function __construct(BackgroundAgentOrchestrator $orchestrator)
    {
        $this->orchestrator = $orchestrator;
    }

    public function handleContentUpdated(ContentUpdated $event): void
    {
        $this->orchestrator->dispatchForEvent(
            ContentUpdated::class,
            $event->organizationId,
            [
                'content_type' => get_class($event->content),
                'content_id' => $event->content->id,
                'action' => $event->action,
            ]
        );
    }

    public function handleAssessmentSubmitted(AssessmentSubmitted $event): void
    {
        $this->orchestrator->dispatchForEvent(
            AssessmentSubmitted::class,
            $event->organizationId,
            [
                'submission_id' => $event->submission->id,
            ]
        );
    }

    public function subscribe(Dispatcher $events): array
    {
        return [
            ContentUpdated::class => 'handleContentUpdated',
            AssessmentSubmitted::class => 'handleAssessmentSubmitted',
        ];
    }
}
