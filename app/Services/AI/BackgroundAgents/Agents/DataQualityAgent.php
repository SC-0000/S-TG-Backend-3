<?php

namespace App\Services\AI\BackgroundAgents\Agents;

use App\Events\ContentUpdated;
use App\Models\Assessment;
use App\Models\BackgroundAgentAction;
use App\Models\ContentLesson;
use App\Models\ContentQualityIssue;
use App\Models\Course;
use App\Models\Module;
use App\Models\Question;
use App\Services\AI\BackgroundAgents\AbstractBackgroundAgent;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;

/**
 * DATA QUALITY AGENT
 * ==================
 *
 * Scans educational content for missing or incomplete metadata and auto-fixes
 * issues using AI. Covers: Questions, Assessments, Lessons, Courses, and Modules.
 *
 * TRIGGER LOGIC:
 * ─────────────
 * 1. EVENT-DRIVEN (immediate, single item):
 *    When a Question, Assessment, Lesson, Course, or Module is created or
 *    significantly edited (ContentUpdated event), this agent scans ONLY that
 *    item for missing fields. This is the primary trigger — catches issues
 *    the moment content is saved.
 *
 * 2. SCHEDULED (daily at 2 AM, full scan):
 *    Runs a full organisation-wide scan of ALL content. Only checks items
 *    modified since the last scheduled run to stay efficient. Catches anything
 *    the event-driven scan might have missed (e.g. bulk imports, direct DB edits).
 *
 * 3. MANUAL (admin clicks "Run Now", full scan):
 *    Scans ALL content regardless of last_run_at. Useful for initial setup
 *    or after a large content migration.
 *
 * RE-SCAN RULES:
 * ─────────────
 * - An item is only scanned if it has empty/null fields that the agent monitors.
 * - Once all fields are populated and the issue is resolved, the item won't be
 *   flagged again UNLESS it is subsequently edited and a field is cleared/emptied.
 * - Issues are keyed by (target_type, target_id, issue_type) — duplicates are
 *   impossible. If an issue is already open, the agent updates it rather than
 *   creating a second.
 * - Dismissed issues are NOT re-opened on subsequent scans.
 *
 * AUTO-FIX CAPABILITIES:
 * ─────────────────────
 * - Descriptions: AI-generates concise educational descriptions
 * - Difficulty levels: AI analyses question complexity (1-10 scale)
 * - Time estimates: AI estimates completion time in minutes
 * - Hints: AI generates 3 progressive hints per question
 * - Solutions: AI writes step-by-step solution explanations
 * - Tags: AI generates 3-5 relevant educational tags
 * - Thumbnails: AI generates course thumbnail images
 * - Module durations: AI estimates based on lesson count and content
 *
 * CONTENT COVERAGE:
 * ────────────────
 * Questions ──── description, difficulty_level, estimated_time_minutes,
 *                category, tags, hints, solutions
 * Assessments ── description, journey_category_id, time_limit
 * Lessons ────── description, journey_category_id, estimated_minutes
 * Courses ────── description, thumbnail, cover_image, journey_category_id
 * Modules ────── description, estimated_duration_minutes
 */
class DataQualityAgent extends AbstractBackgroundAgent
{
    /**
     * Quality check definitions per model type.
     * Each check: [field, issue_type, severity, auto_fixable, description]
     */
    protected array $qualityChecks = [
        Question::class => [
            ['description', 'missing_description', 'warning', true, 'Question is missing an explanation/description — students and parents won\'t see context for this question'],
            ['difficulty_level', 'missing_difficulty', 'warning', true, 'Question has no difficulty level — adaptive learning and reporting cannot classify it'],
            ['estimated_time_minutes', 'missing_time_estimate', 'info', true, 'Question has no time estimate — assessment time limits may be inaccurate'],
            ['category', 'missing_category', 'warning', true, 'Question has no category — it cannot be filtered or used in category-based reports'],
            ['tags', 'missing_tags', 'info', true, 'Question has no tags — search and recommendation quality is reduced'],
            ['hints', 'missing_hints', 'info', true, 'Question has no hints — students won\'t have guided help if they get stuck'],
            ['solutions', 'missing_solutions', 'warning', true, 'Question has no worked solution — students can\'t learn from their mistakes'],
        ],
        Assessment::class => [
            ['description', 'missing_description', 'warning', true, 'Assessment is missing a description — parents and students see no context'],
            ['journey_category_id', 'missing_category', 'critical', false, 'Assessment has no journey category — it won\'t appear in curriculum progress tracking'],
            ['time_limit', 'missing_time_limit', 'warning', true, 'Assessment has no time limit — students can take unlimited time'],
        ],
        ContentLesson::class => [
            ['description', 'missing_description', 'warning', true, 'Lesson is missing a description — it will appear blank in course listings'],
            ['journey_category_id', 'missing_category', 'critical', false, 'Lesson has no journey category — progress tracking will not include it'],
            ['estimated_minutes', 'missing_time_estimate', 'info', true, 'Lesson has no duration estimate — course durations will be inaccurate'],
        ],
        Course::class => [
            ['description', 'missing_description', 'warning', true, 'Course is missing a description — it will appear incomplete in the catalogue'],
            ['thumbnail', 'missing_thumbnail', 'warning', true, 'Course has no thumbnail — it will display a generic placeholder'],
            ['cover_image', 'missing_cover_image', 'info', true, 'Course has no cover image — the course page header will be empty'],
            ['journey_category_id', 'missing_category', 'critical', false, 'Course has no journey category — it won\'t appear in curriculum navigation'],
        ],
        Module::class => [
            ['description', 'missing_description', 'warning', true, 'Module is missing a description — students see no context for this section'],
            ['estimated_duration_minutes', 'missing_time_estimate', 'info', true, 'Module has no duration estimate — course progress calculations will be off'],
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
        return 'Scans Questions, Assessments, Lessons, Courses, and Modules for missing metadata (descriptions, difficulty, tags, hints, solutions, thumbnails, time estimates, categories). Auto-fixes issues using AI when content is created, edited, or during the daily 2 AM sweep.';
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
        $isEventDriven = $this->currentRun->trigger_type === 'event';

        if ($isEventDriven) {
            $this->scanEventTriggered();
        } else {
            $this->scanAll();
        }

        // Auto-fix open issues if enabled
        $autoFixEnabled = $this->getConfig('auto_fix_enabled', true);
        if ($autoFixEnabled) {
            $this->autoFixIssues();
        }
    }

    /**
     * Scan a single piece of content triggered by the ContentUpdated event.
     * Only fires when content is created or significantly edited.
     */
    protected function scanEventTriggered(): void
    {
        $summary = $this->currentRun->summary ?? [];
        $contentType = $summary['content_type'] ?? null;
        $contentId = $summary['content_id'] ?? null;

        if (!$contentType || !$contentId) {
            Log::warning('[DataQualityAgent] Event-triggered scan missing content_type or content_id', $summary);
            // Fall back to a full scan instead of silently doing nothing
            $this->scanAll();
            return;
        }

        // Validate the content type is one we monitor
        if (!isset($this->qualityChecks[$contentType])) {
            Log::debug("[DataQualityAgent] Skipping unmonitored content type: {$contentType}");
            return;
        }

        $model = $contentType::find($contentId);
        if (!$model) {
            Log::debug("[DataQualityAgent] Content not found: {$contentType}#{$contentId} — may have been deleted");
            return;
        }

        $this->incrementProcessed();
        $this->checkModel($model);
    }

    /**
     * Full scan of all content for the organisation.
     * Scheduled runs only check content modified since the last run.
     * Manual runs scan everything.
     */
    protected function scanAll(): void
    {
        $orgId = $this->organization?->id;
        $isManual = $this->currentRun->trigger_type === 'manual';

        foreach ($this->qualityChecks as $modelClass => $checks) {
            $query = $modelClass::query();

            if ($orgId) {
                $query->where('organization_id', $orgId);
            }

            // For scheduled runs, only check content updated since last run.
            // For manual runs, always do a full scan.
            if (!$isManual) {
                $config = $this->organization
                    ? \App\Models\BackgroundAgentConfig::getOrCreate($this->organization->id, static::getAgentType())
                    : null;

                if ($config?->last_run_at) {
                    $query->where('updated_at', '>=', $config->last_run_at);
                }
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
     * Records issues for empty fields and auto-resolves issues where the field
     * has been populated since the last scan.
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
                // Field is now populated — resolve any previously open issue.
                // Dismissed issues are left alone (user explicitly chose to ignore).
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
     * Uses updateOrCreate keyed on (target_type, target_id, issue_type) so
     * the same issue is never duplicated. If an issue was previously dismissed,
     * it stays dismissed — we don't re-open it.
     */
    protected function recordIssue(Model $model, string $issueType, string $severity, bool $autoFixable, string $description): void
    {
        // Don't re-open dismissed issues
        $existing = ContentQualityIssue::where('target_type', get_class($model))
            ->where('target_id', $model->id)
            ->where('issue_type', $issueType)
            ->first();

        if ($existing && $existing->status === ContentQualityIssue::STATUS_DISMISSED) {
            return;
        }

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

    // ─────────────────────────────────────────────────────────────────────────
    // AUTO-FIX ENGINE
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Auto-fix open, fixable issues using AI.
     * Processes up to max_auto_fixes_per_run (default 20) issues per run.
     * Stops early if the token budget is exhausted.
     */
    protected function autoFixIssues(): void
    {
        $maxFixes = $this->getConfig('max_auto_fixes_per_run', 20);

        $issues = ContentQualityIssue::fixable()
            ->when($this->organization, fn($q) => $q->forOrganization($this->organization->id))
            ->limit($maxFixes)
            ->get();

        foreach ($issues as $issue) {
            if (!$this->hasRemainingBudget()) {
                Log::info('[DataQualityAgent] Stopping auto-fix: token budget exhausted');
                break;
            }

            try {
                $fixed = $this->attemptAutoFix($issue);
                if ($fixed) {
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
     * Attempt to auto-fix a single issue by dispatching to the appropriate fixer method.
     */
    protected function attemptAutoFix(ContentQualityIssue $issue): bool
    {
        $model = $issue->target_type::find($issue->target_id);
        if (!$model) {
            $issue->dismiss();
            return false;
        }

        // Derive method name from issue_type: missing_description → fixMissingDescription
        $method = 'fix' . str_replace(' ', '', ucwords(str_replace('_', ' ', $issue->issue_type)));

        if (method_exists($this, $method)) {
            return $this->{$method}($model, $issue);
        }

        return $this->fixGenericMissing($model, $issue);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // INDIVIDUAL FIXERS
    // ─────────────────────────────────────────────────────────────────────────

    protected function fixMissingDescription(Model $model, ContentQualityIssue $issue): bool
    {
        $context = $this->buildModelContext($model);
        $modelType = class_basename($model);

        $prompt = "Generate a concise, professional description for this educational {$modelType}:\n\n{$context}\n\nWrite 1-2 sentences that clearly describe what this content covers and what students will learn. Be specific to the content, not generic. Do not use marketing language.";

        $description = trim($this->aiGenerateText($prompt, 'You are an educational content specialist writing for a UK 11+ tutoring platform.'));

        // Validate: must be non-empty, at least 10 chars, and not just the prompt echoed back
        if (strlen($description) < 10) {
            Log::warning("[DataQualityAgent] Description too short for {$modelType}#{$model->id}: \"{$description}\"");
            return false;
        }

        $before = ['description' => $model->description];
        $model->update(['description' => $description]);

        $this->logAction(
            BackgroundAgentAction::ACTION_AUTO_FIX, $model,
            "Generated description for {$modelType}#{$model->id}: \"" . \Illuminate\Support\Str::limit($description, 80) . '"',
            $before, ['description' => $description]
        );

        $issue->markFixed('agent');
        return true;
    }

    protected function fixMissingDifficulty(Model $model, ContentQualityIssue $issue): bool
    {
        if (!($model instanceof Question)) return false;

        $context = $this->buildModelContext($model);
        $prompt = "Analyse this question and rate its difficulty on a scale of 1-10 (1=trivial, 5=average, 10=very challenging for the target age group):\n\n{$context}\n\nRespond with ONLY a single number from 1-10.";

        $result = $this->aiGenerateText($prompt, 'You are an experienced UK 11+ examiner who rates question difficulty accurately.');
        $difficulty = (int) preg_replace('/[^0-9]/', '', $result);
        $difficulty = max(1, min(10, $difficulty ?: 5));

        $before = ['difficulty_level' => $model->difficulty_level];
        $model->update(['difficulty_level' => $difficulty]);

        $this->logAction(BackgroundAgentAction::ACTION_AUTO_FIX, $model,
            "Set difficulty to {$difficulty}/10 for Question#{$model->id}", $before, ['difficulty_level' => $difficulty]);

        $issue->markFixed('agent');
        return true;
    }

    protected function fixMissingTimeEstimate(Model $model, ContentQualityIssue $issue): bool
    {
        $context = $this->buildModelContext($model);
        $modelType = class_basename($model);

        if ($model instanceof Question) {
            $prompt = "Estimate how many minutes a student would need to answer this question:\n\n{$context}\n\nRespond with ONLY a number (minutes, can be decimal like 1.5).";
            $maxMinutes = 60;
            $field = 'estimated_time_minutes';
        } elseif ($model instanceof ContentLesson) {
            $prompt = "Estimate the total duration in minutes for this lesson:\n\n{$context}\n\nConsider the content depth and student age group. Respond with ONLY a number.";
            $maxMinutes = 120;
            $field = 'estimated_minutes';
        } elseif ($model instanceof Module) {
            $lessonCount = $model->lessons()->count();
            $prompt = "Estimate total duration in minutes for this module containing {$lessonCount} lessons:\n\n{$context}\n\nRespond with ONLY a number.";
            $maxMinutes = 600;
            $field = 'estimated_duration_minutes';
        } elseif ($model instanceof Assessment) {
            $questionCount = $model->bankQuestions()->count() + $model->inlineQuestions()->count();
            $prompt = "Estimate a reasonable time limit in minutes for this assessment with {$questionCount} questions:\n\n{$context}\n\nRespond with ONLY a number.";
            $maxMinutes = 180;
            $field = 'time_limit';
        } else {
            return false;
        }

        $result = $this->aiGenerateText($prompt, 'You are an experienced UK 11+ tutor who accurately estimates task durations.');
        $minutes = (float) preg_replace('/[^0-9.]/', '', $result);
        $minutes = max(0.5, min($maxMinutes, $minutes ?: 2));

        $before = [$field => $model->{$field}];
        $model->update([$field => round($minutes, 1)]);

        $this->logAction(BackgroundAgentAction::ACTION_AUTO_FIX, $model,
            "Set {$field} to " . round($minutes, 1) . " min for {$modelType}#{$model->id}",
            $before, [$field => round($minutes, 1)]);

        $issue->markFixed('agent');
        return true;
    }

    // Alias: missing_time_limit routes to the same fixer
    protected function fixMissingTimeLimit(Model $model, ContentQualityIssue $issue): bool
    {
        return $this->fixMissingTimeEstimate($model, $issue);
    }

    protected function fixMissingHints(Model $model, ContentQualityIssue $issue): bool
    {
        if (!($model instanceof Question)) return false;

        $context = $this->buildModelContext($model);
        $prompt = "Generate 3 progressive hints for this question. Each hint should give a bit more information without revealing the answer. The first hint should be very subtle, the third quite direct.\n\n{$context}\n\nReturn as JSON: {\"hints\": [\"hint1\", \"hint2\", \"hint3\"]}";

        $result = $this->aiGenerateStructured($prompt, 'You are an experienced 11+ tutor who gives pedagogically sound hints.');
        $hints = $result['hints'] ?? [];

        // Validate: must be a non-empty array of strings, each at least 5 chars
        $hints = array_values(array_filter(
            is_array($hints) ? $hints : [],
            fn($h) => is_string($h) && strlen(trim($h)) >= 5
        ));
        $hints = array_map('trim', $hints);

        if (empty($hints)) {
            Log::warning("[DataQualityAgent] Hints generation returned empty/invalid for Question#{$model->id}");
            return false;
        }

        $before = ['hints' => $model->hints];
        $model->update(['hints' => $hints]);

        $this->logAction(BackgroundAgentAction::ACTION_AUTO_FIX, $model,
            "Generated " . count($hints) . " progressive hints for Question#{$model->id}",
            $before, ['hints' => $hints]);

        $issue->markFixed('agent');
        return true;
    }

    protected function fixMissingSolutions(Model $model, ContentQualityIssue $issue): bool
    {
        if (!($model instanceof Question)) return false;

        $context = $this->buildModelContext($model);
        $prompt = "Write a clear, step-by-step worked solution for this question that would help a student understand how to arrive at the correct answer:\n\n{$context}\n\nBe thorough but age-appropriate. Show the working, not just the answer.";

        $solution = trim($this->aiGenerateText($prompt, 'You are an experienced 11+ tutor who explains solutions clearly to 10-11 year old students.'));

        // Validate: must be a substantive explanation, not just a number or one word
        if (strlen($solution) < 20) {
            Log::warning("[DataQualityAgent] Solution too short for Question#{$model->id}: \"{$solution}\"");
            return false;
        }

        // solutions is cast as array in the Question model — store as single-element array
        $solutionArray = [$solution];

        $before = ['solutions' => $model->solutions];
        $model->update(['solutions' => $solutionArray]);

        $this->logAction(BackgroundAgentAction::ACTION_AUTO_FIX, $model,
            "Generated worked solution for Question#{$model->id}",
            $before, ['solutions' => [\Illuminate\Support\Str::limit(trim($solution), 200)]]);

        $issue->markFixed('agent');
        return true;
    }

    protected function fixMissingTags(Model $model, ContentQualityIssue $issue): bool
    {
        if (!($model instanceof Question)) return false;

        $context = $this->buildModelContext($model);
        $prompt = "Analyse this question and generate 3-5 relevant educational tags for search and categorisation:\n\n{$context}\n\nReturn as JSON: {\"tags\": [\"tag1\", \"tag2\", ...]}. Use lowercase, single or two-word tags.";

        $result = $this->aiGenerateStructured($prompt, 'You are an educational content taxonomist for UK 11+ curriculum.');
        $tags = $result['tags'] ?? [];

        // Validate: must be a non-empty array of short lowercase strings
        $tags = array_values(array_filter(
            is_array($tags) ? $tags : [],
            fn($t) => is_string($t) && strlen(trim($t)) >= 2 && strlen(trim($t)) <= 50
        ));
        $tags = array_map(fn($t) => strtolower(trim($t)), $tags);

        if (empty($tags)) {
            Log::warning("[DataQualityAgent] Tags generation returned empty/invalid for Question#{$model->id}");
            return false;
        }

        $before = ['tags' => $model->tags];
        $model->update(['tags' => $tags]);

        $this->logAction(BackgroundAgentAction::ACTION_AUTO_FIX, $model,
            "Generated " . count($tags) . " tags for Question#{$model->id}: " . implode(', ', $tags),
            $before, ['tags' => $tags]);

        $issue->markFixed('agent');
        return true;
    }

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
                "Generated thumbnail for Course#{$model->id}",
                $before, ['thumbnail' => $imageResult['storage_path']]);

            $issue->markFixed('agent');
            return true;
        } catch (\Exception $e) {
            Log::warning("[DataQualityAgent] Thumbnail generation failed for Course#{$model->id}: " . $e->getMessage());
            return false;
        }
    }

    protected function fixMissingCategory(Model $model, ContentQualityIssue $issue): bool
    {
        // Category assignment requires human judgement — not auto-fixable.
        return false;
    }

    protected function fixMissingCoverImage(Model $model, ContentQualityIssue $issue): bool
    {
        if (!($model instanceof Course)) return false;

        $prompt = "Wide educational banner image: {$model->title}. Professional, inviting design for an 11+ tutoring course. Landscape format, vibrant educational theme, no text.";

        try {
            $imageResult = $this->aiGenerateImage($prompt, [
                'width' => 1200,
                'height' => 400,
            ]);

            $before = ['cover_image' => $model->cover_image];
            $model->update(['cover_image' => $imageResult['storage_path']]);

            $this->logAction(BackgroundAgentAction::ACTION_AUTO_FIX, $model,
                "Generated cover image for Course#{$model->id}",
                $before, ['cover_image' => $imageResult['storage_path']]);

            $issue->markFixed('agent');
            return true;
        } catch (\Exception $e) {
            Log::warning("[DataQualityAgent] Cover image generation failed for Course#{$model->id}: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Generic fallback fixer for text-based fields.
     */
    protected function fixGenericMissing(Model $model, ContentQualityIssue $issue): bool
    {
        $textIssues = ['missing_description', 'missing_solutions'];
        if (!in_array($issue->issue_type, $textIssues)) {
            return false;
        }

        return $this->fixMissingDescription($model, $issue);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // HELPERS
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Build a context string from the model's key fields for AI prompts.
     */
    protected function buildModelContext(Model $model): string
    {
        $parts = [];
        $parts[] = 'Type: ' . class_basename($model);

        if (isset($model->title)) $parts[] = "Title: {$model->title}";
        if (isset($model->question_data) && $model->question_data) $parts[] = 'Question Data: ' . json_encode($model->question_data);
        if (isset($model->answer_schema) && $model->answer_schema) $parts[] = 'Answer Schema: ' . json_encode($model->answer_schema);
        if (isset($model->category) && $model->category) $parts[] = "Category: {$model->category}";
        if (isset($model->subcategory) && $model->subcategory) $parts[] = "Subcategory: {$model->subcategory}";
        if (isset($model->question_type) && $model->question_type) $parts[] = "Question Type: {$model->question_type}";
        if (isset($model->year_group) && $model->year_group) $parts[] = "Year Group: {$model->year_group}";
        if (isset($model->grade) && $model->grade) $parts[] = "Grade: {$model->grade}";
        if (isset($model->description) && $model->description) $parts[] = "Description: {$model->description}";
        if (isset($model->lesson_type) && $model->lesson_type) $parts[] = "Lesson Type: {$model->lesson_type}";
        if (isset($model->delivery_mode) && $model->delivery_mode) $parts[] = "Delivery Mode: {$model->delivery_mode}";
        if (isset($model->level) && $model->level) $parts[] = "Level: {$model->level}";

        return implode("\n", $parts);
    }
}
