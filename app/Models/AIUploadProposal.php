<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AIUploadProposal extends Model
{
    protected $table = 'ai_upload_proposals';

    protected $fillable = [
        'session_id',
        'content_type',
        'status',
        'proposed_data',
        'original_data',
        'is_valid',
        'validation_errors',
        'quality_score',
        'quality_metrics',
        'parent_proposal_id',
        'parent_type',
        'order_position',
        'created_model_type',
        'created_model_id',
        'user_modifications',
        'modified_by',
        'modified_at',
    ];

    protected $casts = [
        'proposed_data' => 'array',
        'original_data' => 'array',
        'validation_errors' => 'array',
        'quality_metrics' => 'array',
        'user_modifications' => 'array',
        'is_valid' => 'boolean',
        'quality_score' => 'decimal:2',
        'modified_at' => 'datetime',
    ];

    // Content types (same as session)
    const TYPE_QUESTION = 'question';
    const TYPE_ASSESSMENT = 'assessment';
    const TYPE_COURSE = 'course';
    const TYPE_MODULE = 'module';
    const TYPE_LESSON = 'lesson';
    const TYPE_SLIDE = 'slide';
    const TYPE_ARTICLE = 'article';

    // Statuses
    const STATUS_PENDING = 'pending';
    const STATUS_APPROVED = 'approved';
    const STATUS_REJECTED = 'rejected';
    const STATUS_MODIFIED = 'modified';
    const STATUS_UPLOADED = 'uploaded';

    // Relationships
    public function session(): BelongsTo
    {
        return $this->belongsTo(AIUploadSession::class, 'session_id');
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(AIUploadProposal::class, 'parent_proposal_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(AIUploadProposal::class, 'parent_proposal_id')
            ->orderBy('order_position');
    }

    public function modifiedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'modified_by');
    }

    public function logs(): HasMany
    {
        return $this->hasMany(AIUploadLog::class, 'proposal_id');
    }

    // Scopes
    public function scopePending($query)
    {
        return $query->where('status', self::STATUS_PENDING);
    }

    public function scopeApproved($query)
    {
        return $query->where('status', self::STATUS_APPROVED);
    }

    public function scopeValid($query)
    {
        return $query->where('is_valid', true);
    }

    public function scopeByContentType($query, string $contentType)
    {
        return $query->where('content_type', $contentType);
    }

    public function scopeRootLevel($query)
    {
        return $query->whereNull('parent_proposal_id');
    }

    // Helpers
    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    public function isApproved(): bool
    {
        return $this->status === self::STATUS_APPROVED;
    }

    public function isRejected(): bool
    {
        return $this->status === self::STATUS_REJECTED;
    }

    public function isUploaded(): bool
    {
        return $this->status === self::STATUS_UPLOADED;
    }

    public function approve(): void
    {
        $this->update(['status' => self::STATUS_APPROVED]);
    }

    public function reject(): void
    {
        $this->update(['status' => self::STATUS_REJECTED]);
    }

    public function markAsUploaded(string $modelType, int $modelId): void
    {
        $this->update([
            'status' => self::STATUS_UPLOADED,
            'created_model_type' => $modelType,
            'created_model_id' => $modelId,
        ]);
    }

    /**
     * Get a specific field from proposed data
     */
    public function getProposedField(string $key, $default = null)
    {
        return data_get($this->proposed_data, $key, $default);
    }

    /**
     * Update proposed data with user modifications
     */
    public function updateProposedData(array $modifications, int $userId): void
    {
        $this->update([
            'proposed_data' => array_merge($this->proposed_data ?? [], $modifications),
            'user_modifications' => $modifications,
            'modified_by' => $userId,
            'modified_at' => now(),
            'status' => self::STATUS_MODIFIED,
        ]);
    }

    /**
     * Get the title/name for display
     */
    public function getDisplayTitle(): string
    {
        return $this->getProposedField('title') 
            ?? $this->getProposedField('name')
            ?? "Untitled {$this->content_type}";
    }

    /**
     * Get all validation errors as a flat array of messages
     */
    public function getValidationMessages(): array
    {
        if (!$this->validation_errors) {
            return [];
        }

        $messages = [];
        foreach ($this->validation_errors as $field => $errors) {
            if (is_array($errors)) {
                foreach ($errors as $error) {
                    $messages[] = "{$field}: {$error}";
                }
            } else {
                $messages[] = $errors;
            }
        }
        return $messages;
    }

    /**
     * Validate the proposed data against model requirements
     */
    public function validate(): bool
    {
        $errors = [];
        $data = $this->proposed_data ?? [];

        switch ($this->content_type) {
            case self::TYPE_QUESTION:
                $errors = $this->validateQuestion($data);
                break;
            case self::TYPE_ASSESSMENT:
                $errors = $this->validateAssessment($data);
                break;
            case self::TYPE_COURSE:
                $errors = $this->validateCourse($data);
                break;
            case self::TYPE_MODULE:
                $errors = $this->validateModule($data);
                break;
            case self::TYPE_LESSON:
                $errors = $this->validateLesson($data);
                break;
            case self::TYPE_SLIDE:
                $errors = $this->validateSlide($data);
                break;
            case self::TYPE_ARTICLE:
                $errors = $this->validateArticle($data);
                break;
        }

        $isValid = empty($errors);
        
        $this->update([
            'is_valid' => $isValid,
            'validation_errors' => $errors ?: null,
        ]);

        return $isValid;
    }

    protected function validateQuestion(array $data): array
    {
        $errors = [];
        
        if (empty($data['title'])) {
            $errors['title'] = ['Title is required'];
        }
        if (empty($data['question_type'])) {
            $errors['question_type'] = ['Question type is required'];
        }
        if (empty($data['question_data'])) {
            $errors['question_data'] = ['Question data is required'];
        }
        if (!isset($data['marks']) || $data['marks'] < 0) {
            $errors['marks'] = ['Marks must be a positive number'];
        }
        
        // Validate question_data structure based on type
        if (!empty($data['question_type']) && !empty($data['question_data'])) {
            $qData = $data['question_data'];
            if (empty($qData['question_text'])) {
                $errors['question_data.question_text'] = ['Question text is required'];
            }
            
            // Type-specific validation
            if ($data['question_type'] === 'mcq') {
                if (empty($qData['options']) || count($qData['options']) < 2) {
                    $errors['question_data.options'] = ['MCQ must have at least 2 options'];
                }
            }
        }

        return $errors;
    }

    protected function validateAssessment(array $data): array
    {
        $errors = [];
        
        if (empty($data['title'])) {
            $errors['title'] = ['Title is required'];
        }
        
        return $errors;
    }

    protected function validateCourse(array $data): array
    {
        $errors = [];
        
        if (empty($data['title'])) {
            $errors['title'] = ['Title is required'];
        }
        
        return $errors;
    }

    protected function validateModule(array $data): array
    {
        $errors = [];
        
        if (empty($data['title'])) {
            $errors['title'] = ['Title is required'];
        }
        
        return $errors;
    }

    protected function validateLesson(array $data): array
    {
        $errors = [];
        
        if (empty($data['title'])) {
            $errors['title'] = ['Title is required'];
        }
        
        return $errors;
    }

    protected function validateSlide(array $data): array
    {
        $errors = [];
        
        if (empty($data['title'])) {
            $errors['title'] = ['Title is required'];
        }
        if (empty($data['blocks']) || !is_array($data['blocks'])) {
            $errors['blocks'] = ['Slide must have at least one block'];
        }
        
        return $errors;
    }

    protected function validateArticle(array $data): array
    {
        $errors = [];
        
        if (empty($data['organization_id'])) {
            $errors['organization_id'] = ['Organization is required'];
        }
        if (empty($data['category'])) {
            $errors['category'] = ['Category is required'];
        }
        if (empty($data['tag'])) {
            $errors['tag'] = ['Tag is required'];
        }
        if (empty($data['name'])) {
            $errors['name'] = ['Name is required'];
        }
        if (empty($data['title'])) {
            $errors['title'] = ['Title is required'];
        }
        if (empty($data['description'])) {
            $errors['description'] = ['Description is required'];
        }
        if (empty($data['body_type'])) {
            $errors['body_type'] = ['Body type is required'];
        }
        if (empty($data['author'])) {
            $errors['author'] = ['Author is required'];
        }
        if (empty($data['scheduled_publish_date'])) {
            $errors['scheduled_publish_date'] = ['Scheduled publish date is required'];
        }
        if (empty($data['sections']) || !is_array($data['sections'])) {
            $errors['sections'] = ['Sections are required'];
        }
        
        return $errors;
    }

    /**
     * Create the actual model from this proposal
     */
    public function createModel(int $organizationId = null): ?Model
    {
        if (!$this->is_valid) {
            return null;
        }

        $data = $this->proposed_data;
        
        if ($organizationId) {
            $data['organization_id'] = $organizationId;
        }

        $model = null;

        switch ($this->content_type) {
            case self::TYPE_QUESTION:
                $model = Question::create($data);
                break;
            case self::TYPE_ASSESSMENT:
                $model = Assessment::create($data);
                break;
            case self::TYPE_COURSE:
                $model = Course::create($data);
                break;
            case self::TYPE_MODULE:
                $model = Module::create($data);
                break;
            case self::TYPE_LESSON:
                $model = ContentLesson::create($data);
                break;
            case self::TYPE_SLIDE:
                $model = LessonSlide::create($data);
                break;
            case self::TYPE_ARTICLE:
                $model = Article::create($data);
                break;
        }

        if ($model) {
            $this->markAsUploaded(get_class($model), $model->id);
        }

        return $model;
    }

    /**
     * Get the created model instance
     */
    public function getCreatedModel(): ?Model
    {
        if (!$this->created_model_type || !$this->created_model_id) {
            return null;
        }

        return $this->created_model_type::find($this->created_model_id);
    }
}
