<?php

namespace App\Jobs;

use App\Models\CallLog;
use App\Services\AI\AIUtilityService;
use App\Services\AI\TokenBillingService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class TranscribeCallJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;
    public int $timeout = 120;

    public function __construct(
        protected int $callLogId,
    ) {}

    public function handle(AIUtilityService $ai, TokenBillingService $billing): void
    {
        $callLog = CallLog::find($this->callLogId);
        if (!$callLog || !$callLog->recording_url) {
            return;
        }

        try {
            // Step 1: Transcribe the recording using OpenAI Whisper
            $transcription = $this->transcribe($callLog->recording_url);

            if (!$transcription) {
                $callLog->update(['recording_status' => 'failed']);
                return;
            }

            $callLog->update(['transcription' => $transcription]);

            // Step 2: Generate AI summary of the call
            $summary = $ai->generateText(
                "Summarise this phone call transcription between a tuition company staff member and a parent. "
                . "Focus on: key topics discussed, action items, decisions made, and any follow-ups needed. "
                . "Keep it concise (3-5 bullet points).\n\nTranscription:\n{$transcription}",
                'You are a professional call summariser for an education/tutoring company.',
                ['model' => 'gpt-5.4-nano']
            );

            $callLog->update([
                'ai_summary' => $summary['text'] ?? $summary,
                'recording_status' => 'ready',
            ]);

            // Bill tokens for transcription + summary
            $org = $callLog->organization;
            if ($org) {
                $tokens = $billing->calculatePlatformTokens(
                    $summary['model'] ?? 'gpt-5.4-nano',
                    'text_generation',
                    $summary['usage']['prompt_tokens'] ?? 0,
                    $summary['usage']['completion_tokens'] ?? 0,
                );
                $tokens += 5; // Flat charge for Whisper transcription

                if ($billing->hasBalance($org, $tokens)) {
                    $billing->deduct($org, $tokens, 'call_transcription', $callLog->id, 'Call transcription + AI summary');
                }
            }

            Log::info('[TranscribeCall] Completed', ['call_log_id' => $callLog->id]);

        } catch (\Throwable $e) {
            Log::error('[TranscribeCall] Failed', [
                'call_log_id' => $callLog->id,
                'error' => $e->getMessage(),
            ]);
            $callLog->update(['recording_status' => 'failed']);
        }
    }

    /**
     * Transcribe audio using OpenAI Whisper API.
     */
    protected function transcribe(string $recordingUrl): ?string
    {
        $apiKey = config('services.openai.key');
        if (!$apiKey) {
            Log::warning('[TranscribeCall] No OpenAI API key');
            return null;
        }

        // Download the recording
        $audioResponse = Http::timeout(60)->get($recordingUrl);
        if (!$audioResponse->successful()) {
            Log::warning('[TranscribeCall] Failed to download recording');
            return null;
        }

        $tempFile = tempnam(sys_get_temp_dir(), 'call_') . '.mp3';
        file_put_contents($tempFile, $audioResponse->body());

        try {
            $response = Http::withToken($apiKey)
                ->timeout(90)
                ->attach('file', file_get_contents($tempFile), 'recording.mp3')
                ->post('https://api.openai.com/v1/audio/transcriptions', [
                    'model' => 'whisper-1',
                    'language' => 'en',
                    'response_format' => 'text',
                ]);

            return $response->successful() ? trim($response->body()) : null;
        } finally {
            @unlink($tempFile);
        }
    }
}
