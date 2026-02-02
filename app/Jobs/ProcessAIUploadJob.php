<?php

namespace App\Jobs;

use App\Models\AIUploadSession;
use App\Services\AI\Agents\ContentUploadAgent;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ProcessAIUploadJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * The number of times the job may be attempted.
     */
    public int $tries = 3;

    /**
     * The number of seconds the job can run before timing out.
     */
    public int $timeout = 600; // 10 minutes

    /**
     * The number of seconds to wait before retrying the job.
     */
    public int $backoff = 30;

    /**
     * Create a new job instance.
     */
    public function __construct(
        public AIUploadSession $session
    ) {}

    /**
     * Execute the job.
     */
    public function handle(ContentUploadAgent $agent): void
    {
        Log::info('ProcessAIUploadJob started', [
            'session_id' => $this->session->id,
            'content_type' => $this->session->content_type,
            'user_id' => $this->session->user_id,
        ]);

        try {
            $result = $agent->process($this->session);

            Log::info('ProcessAIUploadJob completed', [
                'session_id' => $this->session->id,
                'success' => $result['success'],
                'stats' => $result['stats'] ?? null,
            ]);

        } catch (\Exception $e) {
            Log::error('ProcessAIUploadJob failed', [
                'session_id' => $this->session->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            $this->session->markAsFailed($e->getMessage());

            throw $e;
        }
    }

    /**
     * Handle a job failure.
     */
    public function failed(\Throwable $exception): void
    {
        Log::error('ProcessAIUploadJob permanently failed', [
            'session_id' => $this->session->id,
            'error' => $exception->getMessage(),
        ]);

        $this->session->markAsFailed('Job failed after ' . $this->tries . ' attempts: ' . $exception->getMessage());
    }

    /**
     * Get the tags that should be assigned to the job.
     */
    public function tags(): array
    {
        return [
            'ai-upload',
            'session:' . $this->session->id,
            'type:' . $this->session->content_type,
            'user:' . $this->session->user_id,
        ];
    }
}
