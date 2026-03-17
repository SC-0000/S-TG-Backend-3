<?php

namespace App\Services\AI\BackgroundAgents\Agents;

use App\Events\ContentUpdated;
use App\Models\Assessment;
use App\Models\BackgroundAgentAction;
use App\Models\ContentLesson;
use App\Models\ContentQualityIssue;
use App\Models\Course;
use App\Models\JourneyCategory;
use App\Models\Question;
use App\Services\AI\BackgroundAgents\AbstractBackgroundAgent;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;

class DataQualityAgent extends AbstractBackgroundAgent
{
    /**
     * Quality check definitions per model type.
     * Each check: [field, issue_type, severity, auto_fixable, description]
     */
    protected array $qualityChecks = [
        Question::class => [
            ['description', 'missing_description', 'warning', true, 'Question is missing an explanation/description'],
            ['difficulty_level', 'missing_difficulty', 'warning', true, 'Question has no difficulty level set'],
            ['estimated_time_minutes', 'missing_time_estimate', 'info', true, 'Question has no estimated time'],
            ['category', 'missing_category', 'warning', true, 'Question has no category assigned'],
            ['tags', 'missing_tags', 'info', true, 'Question has no tags'],
            ['hints', 'missing_hints', 'info', true, 'Question has no hints for students'],
            ['solutions', 'missing_solutions', 'warning', true, 'Question has no solution explanation'],
        ],
        Assessment::class => [
            ['description', 'missing_description', 'warning', true, 'Assessment is missing a description'],
            ['journey_category_id', 'missing_category', 'critical', false, 'Assessment has no journey category assigned'],
            ['time_limit', 'missing_time_limit', 'warning', true, 'Assessment has no time limit set'],
        ],
        ContentLesson::class => [
            ['description', 'missing_description', 'warning', true, 'Lesson is missing a description'],
            ['journey_category_id', 'missing_category', 'critical', false, 'Lesson has no journey category assigned'],
            ['estimated_minutes', 'missing_time_estimate', 'info', true, 'Lesson has no estimated duration'],
        ],
        Course::class => [
            ['description', 'missing_description', 'warning', true, 'Course is missing a description'],
            ['thumbnail', 'missing_thumbnail', 'warning', true, 'Course has no thumbnail image'],
            ['cover_image', 'missing_cover_image', 'info', true, 'Course has no cover image'],
            ['journey_category_id', 'missing_category', 'critical', false, 'Course has no journey category assigned'],
        ],
    ];

    public static function getAgentType(): string
    {
        return 'data_quality';
    }

    public static function getDefaultSchedule(): string
    {
        return '0 2 * * *'; // Daily at 2 AM
    }

    public static function getDescription(): string
    {
        return 'Monitors content quality, identifies missing metadata, and auto-fixes issues using AI.';
    }

    public static function getEstimatedTokensPerRun(): int
    {
        return 50;
    }

    public static function getEventTriggers(): array
    {
        return [ContentUpdated::class];
    }

    protected function execute(): void
    {
        $orgId = $this->organization?->id;

        // Determine scope: event-driven (single item) or full scan
        $context = $this->currentRun->trigger_reference;
        $isEventDriven = $this->currentRun->trigger_type === 'event';

        if ($isEventDriven) {
            $this->scanEventTriggered();
        } else {
            $this->scanAll();
        }

        // Auto-fix issues if enabled
        $autoFixEnabled = $this->getConfig('auto_fix_enabled', true);
        if ($autoFixEnabled) {
            $this->autoFixIssues();
        }
    }

    /**
     * Scan a single piece of content triggered by an event.
     */
    protected function scanEventTriggered(): void
    {
        $summary = $this->currentRun->summary ?? [];
        $contentType = $summary['content_type'] ?? null;
        $contentId = $summary['content_id'] ?? null;

        if (!$contentType || !$contentId) {
            return;
        }

        $model = $contentType::find($contentId);
        if (!$model) {
            return;
        }

        $this->checkModel($model);
    }

    /**
     * Full scan of all content for the organization.
     */
    protected function scanAll(): void
    {
        $orgId = $this->organization?->id;

        foreach ($this->qualityChecks as $modelClass => $checks) {
            $query = $modelClass::query();

            if ($orgId) {
                $query->where('organization_id', $orgId);
            }

            // For scheduled runs, only check content updated since last run
            $config = $this->organization
                ? \App\Models\BackgroundAgentConfig::getOrCreate($this->organization->id, static::getAgentType())
                : null;

            if ($config?->last_run_at) {
                $query->where('updated_at', '>=', $config->last_run_at);
            }

            $query->chunk(100, function ($items) {
                foreach ($items as $item) {
                    $this->checkModel($item);
                    $this->incrementProcessed();
                }
            });
        }
    }

    /**
     * Check a single model against its quality rules.
     */
    protected function checkModel(Model $model): void
    {
        $modelClass = get_class($model);
        $checks = $this->qualityChecks[$modelClass] ?? [];

        foreach ($checks as [$field, $issueType, $severity, $autoFixable, $description]) {
            $value = $model->{$field};
            $isEmpty = $this->isFieldEmpty($value);

            if ($isEmpty) {
                $this->recordIssue($model, $issueType, $severity, $autoFixable, $description);
            } else {
                // Resolve previously open issue if field is now populated
                ContentQualityIssue::where('target_type', $modelClass)
                    ->where('target_id', $model->id)
                    ->where('issue_type', $issueType)
                    ->where('status', ContentQualityIssue::STATUS_OPEN)
                    ->update([
                        'status' => ContentQualityIssue::STATUS_MANUALLY_FIXED,
                        'fixed_at' => now(),
                        'fixed_by' => 'manual',
                    ]);
            }
        }
    }

    /**
     * Check if a field value is considered empty/missing.
     */
    protected function isFieldEmpty(mixed $value): bool
    {
        if (is_null($value)) return true;
        if (is_string($value) && trim($value) === '') return true;
        if (is_array($value) && empty($value)) return true;
        return false;
    }

    /**
     * Record or update a quality issue.
     */
    protected function recordIssue(Model $model, string $issueType, string $severity, bool $autoFixable, string $description): void
    {
        ContentQualityIssue::updateOrCreate(
            [
                'target_type' => get_class($model),
                'target_id' => $model->id,
                'issue_type' => $issueType,
            ],
            [
                'organization_id' => $model->organization_id ?? $this->organization?->id,
                'run_id' => $this->currentRun->id,
                'severity' => $severity,
                'description' => $description,
                'auto_fixable' => $autoFixable,
                'status' => ContentQualityIssue::STATUS_OPEN,
                'fixed_at' => null,
                'fixed_by' => null,
            ]
        );
    }

    /**
     * Auto-fix open, fixable issues using AI.
     */
    protected function autoFixIssues(): void
    {
        $maxFixes = $this->getConfig('max_auto_fixes_per_run', 20);
        $fixCount = 0;

        $issues = ContentQualityIssue::fixable()
            ->when($this->organization, fn($q) => $q->forOrganization($this->organization->id))
            ->limit($maxFixes)
            ->get();

        foreach ($issues as $issue) {
            if (!$this->hasRemainingBudget()) {
                Log::info("[DataQualityAgent] Stopping auto-fix: budget exhausted");
                break;
            }

            try {
                $fixed = $this->attemptAutoFix($issue);
                if ($fixed) {
                    $fixCount++;
                    $this->incrementAffected();
                }
            } catch (\Exception $e) {
                $this->logAction(
                    BackgroundAgentAction::ACTION_AUTO_FIX,
                    null,
                    "Auto-fix failed for {$issue->issue_type} on {$issue->target_type}#{$issue->target_id}",
                    null,
                    null,
                    0,
                    BackgroundAgentAction::STATUS_FAILED,
                    $e->getMessage()
                );
            }
        }
    }

    /**
     * Attempt to auto-fix a single issue.
     */
    protected function attemptAutoFix(ContentQualityIssue $issue): bool
    {
        $model = $issue->target_type::find($issue->target_id);
        if (!$model) {
            $issue->dismiss();
            return false;
        }

        $method = 'fix' . str_replace(' ', '', ucwords(str_replace('_', ' ', $issue->issue_type)));

        if (method_exists($this, $method)) {
            return $this->{$method}($model, $issue);
        }

        return $this->fixGenericMissing($model, $issue);
    }

    /**
     * Fix missing description for any model.
     */
    protected function fixMissingDescription(Model $model, ContentQualityIssue $issue): bool
    {
        $context = $this->buildModelContext($model);
        $prompt = "Generate a concise, professional description for this educational content:\n\n{$context}\n\nWrite 1-2 sentences that clearly describe what this content covers. Do not use marketing language.";

        $description = $this->aiGenerateText($prompt, 'You are an educational content specialist.');

        $before = ['description' => $model->description];
        $model->update(['description' => trim($description)]);

        $this->logAction(
            BackgroundAgentAction::ACTION_AUTO_FIX,
            $model,
            "Generated description for {$issue->target_type}#{$issue->target_id}",
            $before,
            ['description' => trim($description)]
        );

        $issue->markFixed('agent');
        return true;
    }

    /**
     * Fix missing difficulty level for questions.
     */
    protected function fixMissingDifficulty(Model $model, ContentQualityIssue $issue): bool
    {
        if (!($model instanceof Question)) return false;

        $context = $this->buildModelContext($model);
        $prompt = "Analyze this question and rate its difficulty on a scale of 1-10:\n\n{$context}\n\nRespond with ONLY a number from 1-10.";

        $result = $this->aiGenerateText($prompt, 'You are an educational assessment specialist.');
        $difficulty = (int) preg_replace('/[^0-9]/', '', $result);
        $difficulty = max(1, min(10, $difficulty ?: 5));

        $before = ['difficulty_level' => $model->difficulty_level];
        $model->update(['difficulty_level' => $difficulty]);

        $this->logAction(BackgroundAgentAction::ACTION_AUTO_FIX, $model,
            "Set difficulty to {$difficulty} for Question#{$model->id}", $before, ['difficulty_level' => $difficulty]);

        $issue->markFixed('agent');
        return true;
    }

    /**
     * Fix missing time estimate for questions.
     */
    protected function fixMissingTimeEstimate(Model $model, ContentQualityIssue $issue): bool
    {
        if (!($model instanceof Question)) return false;

        $context = $this->buildModelContext($model);
        $prompt = "Estimate how many minutes a student would need to answer this question:\n\n{$context}\n\nRespond with ONLY a number (minutes, can be decimal like 1.5).";

        $result = $this->aiGenerateText($prompt, 'You are an educational assessment specialist.');
        $minutes = (float) preg_replace('/[^0-9.]/', '', $result);
        $minutes = max(0.5, min(60, $minutes ?: 2));

        $before = ['estimated_time_minutes' => $model->estimated_time_minutes];
        $model->update(['estimated_time_minutes' => round($minutes, 1)]);

        $this->logAction(BackgroundAgentAction::ACTION_AUTO_FIX, $model,
            "Set time estimate to {$minutes}min for Question#{$model->id}", $before, ['estimated_time_minutes' => round($minutes, 1)]);

        $issue->markFixed('agent');
        return true;
    }

    /**
     * Fix missing hints for questions.
     */
    protected function fixMissingHints(Model $model, ContentQualityIssue $issue): bool
    {
        if (!($model instanceof Question)) return false;

        $context = $this->buildModelContext($model);
        $prompt = "Generate 3 progressive hints for this question. Each hint should give a bit more information without revealing the answer:\n\n{$context}\n\nReturn as JSON: {\"hints\": [\"hint1\", \"hint2\", \"hint3\"]}";

        $result = $this->aiGenerateStructured($prompt, 'You are an educational specialist who creates helpful hints.');
        $hints = $result['hints'] ?? [];

        if (empty($hints)) return false;

        $before = ['hints' => $model->hints];
        $model->update(['hints' => $hints]);

        $this->logAction(BackgroundAgentAction::ACTION_AUTO_FIX, $model,
            "Generated " . count($hints) . " hints for Question#{$model->id}", $before, ['hints' => $hints]);

        $issue->markFixed('agent');
        return true;
    }

    /**
     * Fix missing solutions for questions.
     */
    protected function fixMissingSolutions(Model $model, ContentQualityIssue $issue): bool
    {
        if (!($model instanceof Question)) return false;

        $context = $this->buildModelContext($model);
        $prompt = "Write a clear, step-by-step solution explanation for this question that would help a student understand how to arrive at the correct answer:\n\n{$context}\n\nBe thorough but concise.";

        $solution = $this->aiGenerateText($prompt, 'You are an experienced 11+ tutor explaining solutions clearly.');

        $before = ['solutions' => $model->solutions];
        $model->update(['solutions' => trim($solution)]);

        $this->logAction(BackgroundAgentAction::ACTION_AUTO_FIX, $model,
            "Generated solution for Question#{$model->id}", $before, ['solutions' => trim($solution)]);

        $issue->markFixed('agent');
        return true;
    }

    /**
     * Fix missing thumbnail by generating an image.
     */
    protected function fixMissingThumbnail(Model $model, ContentQualityIssue $issue): bool
    {
        if (!($model instanceof Course)) return false;

        $prompt = "Educational course thumbnail: {$model->title}. Professional, clean design for an 11+ tutoring course. Vibrant colours, educational theme, no text.";

        try {
            $imageResult = $this->aiGenerateImage($prompt, [
                'width' => 800,
                'height' => 450,
            ]);

            $before = ['thumbnail' => $model->thumbnail];
            $model->update(['thumbnail' => $imageResult['storage_path']]);

            $this->logAction(BackgroundAgentAction::ACTION_AUTO_FIX, $model,
                "Generated thumbnail for Course#{$model->id}", $before, ['thumbnail' => $imageResult['storage_path']]);

            $issue->markFixed('agent');
            return true;
        } catch (\Exception $e) {
            Log::warning("[DataQualityAgent] Image generation failed for Course#{$model->id}: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Fix missing tags for questions.
     */
    protected function fixMissingTags(Model $model, ContentQualityIssue $issue): bool
    {
        if (!($model instanceof Question)) return false;

        $context = $this->buildModelContext($model);
        $prompt = "Analyze this question and generate 3-5 relevant educational tags:\n\n{$context}\n\nReturn as JSON: {\"tags\": [\"tag1\", \"tag2\", ...]}";

        $result = $this->aiGenerateStructured($prompt, 'You are an educational content taxonomist.');
        $tags = $result['tags'] ?? [];

        if (empty($tags)) return false;

        $before = ['tags' => $model->tags];
        $model->update(['tags' => $tags]);

        $this->logAction(BackgroundAgentAction::ACTION_AUTO_FIX, $model,
            "Generated " . count($tags) . " tags for Question#{$model->id}", $before, ['tags' => $tags]);

        $issue->markFixed('agent');
        return true;
    }

    /**
     * Generic fallback fixer for text fields.
     */
    protected function fixGenericMissing(Model $model, ContentQualityIssue $issue): bool
    {
        // Only handle text-based fields generically
        $textIssues = ['missing_description', 'missing_solutions', 'missing_time_limit'];
        if (!in_array($issue->issue_type, $textIssues)) {
            return false;
        }

        return $this->fixMissingDescription($model, $issue);
    }

    /**
     * Build context string from model for AI prompts.
     */
    protected function buildModelContext(Model $model): string
    {
        $parts = [];
        $parts[] = "Type: " . class_basename($model);

        if (isset($model->title)) $parts[] = "Title: {$model->title}";
        if (isset($model->question_data)) $parts[] = "Question Data: " . json_encode($model->question_data);
        if (isset($model->answer_schema)) $parts[] = "Answer Schema: " . json_encode($model->answer_schema);
        if (isset($model->category)) $parts[] = "Category: {$model->category}";
        if (isset($model->subcategory)) $parts[] = "Subcategory: {$model->subcategory}";
        if (isset($model->question_type)) $parts[] = "Question Type: {$model->question_type}";
        if (isset($model->year_group)) $parts[] = "Year Group: {$model->year_group}";
        if (isset($model->grade)) $parts[] = "Grade: {$model->grade}";
        if (isset($model->description) && $model->description) $parts[] = "Description: {$model->description}";

        return implode("\n", $parts);
    }
}
