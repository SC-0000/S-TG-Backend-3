<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Services\QuestionTypeRegistry;

class Question extends Model
{
    protected $fillable = [
        'organization_id',
        'title',
        'category',
        'subcategory',
        'grade',
        'question_type',
        'question_data',
        'answer_schema',
        'difficulty_level',
        'estimated_time_minutes',
        'marks',
        'ai_metadata',
        'image_descriptions',
        'hints',
        'solutions',
        'tags',
        'version',
        'status',
        'created_by',
    ];

    protected $casts = [
        'question_data' => 'array',
        'answer_schema' => 'array',
        'ai_metadata' => 'array',
        'image_descriptions' => 'array',
        'hints' => 'array',
        'solutions' => 'array',
        'tags' => 'array',
        'marks' => 'decimal:2',
        'difficulty_level' => 'integer',
        'estimated_time_minutes' => 'integer',
        'version' => 'integer',
    ];

    // Relationships
    public function submissionItems()
    {
        return $this->hasMany(AssessmentSubmissionItem::class, 'question_id');
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    // Question Type Handler Methods
    public function getTypeHandler()
    {
        return QuestionTypeRegistry::getHandler($this->question_type);
    }

    public function validateQuestionData(): bool
    {
        $handler = $this->getTypeHandler();
        return $handler ? $handler::validate($this->question_data) : false;
    }

    public function gradeResponse(array $response): array
    {
        $handler = $this->getTypeHandler();
        return $handler ? $handler::grade($this->question_data, $this->answer_schema, $response) : [
            'score' => 0,
            'max_score' => $this->marks,
            'feedback' => 'Question type not supported',
            'is_correct' => false
        ];
    }

    public function renderForStudent(): array
    {
        $handler = $this->getTypeHandler();
        return $handler ? $handler::renderForStudent($this->question_data) : [];
    }

    public function renderForAdmin(): array
    {
        $handler = $this->getTypeHandler();
        return $handler ? $handler::renderForAdmin($this->question_data, $this->answer_schema) : [];
    }

    // Scopes
    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    public function scopeForOrganization($query, $organizationId)
    {
        return $query->where('organization_id', $organizationId);
    }

    public function scopeByType($query, $type)
    {
        return $query->where('question_type', $type);
    }

    public function scopeByCategory($query, $category)
    {
        return $query->where('category', $category);
    }

    public function scopeByGrade($query, $grade)
    {
        return $query->where('grade', $grade);
    }

    public function scopeByDifficulty($query, $min = null, $max = null)
    {
        if ($min !== null) {
            $query->where('difficulty_level', '>=', $min);
        }
        if ($max !== null) {
            $query->where('difficulty_level', '<=', $max);
        }
        return $query;
    }

    public function scopeWithTags($query, array $tags)
    {
        return $query->where(function($q) use ($tags) {
            foreach ($tags as $tag) {
                $q->orWhereJsonContains('tags', $tag);
            }
        });
    }

    // Helper Methods
    public function getFormattedDifficulty(): string
    {
        $levels = [
            1 => 'Very Easy',
            2 => 'Easy',
            3 => 'Below Average',
            4 => 'Below Average',
            5 => 'Average',
            6 => 'Above Average',
            7 => 'Above Average',
            8 => 'Hard',
            9 => 'Very Hard',
            10 => 'Expert'
        ];
        
        return $levels[$this->difficulty_level] ?? 'Unknown';
    }

    public function getEstimatedTimeFormatted(): string
    {
        if (!$this->estimated_time_minutes) {
            return 'Not specified';
        }
        
        if ($this->estimated_time_minutes < 60) {
            return $this->estimated_time_minutes . ' min';
        }
        
        $hours = floor($this->estimated_time_minutes / 60);
        $minutes = $this->estimated_time_minutes % 60;
        
        return $minutes > 0 ? "{$hours}h {$minutes}m" : "{$hours}h";
    }

    public function duplicate(): self
    {
        $duplicate = $this->replicate();
        $duplicate->title = $this->title . ' (Copy)';
        $duplicate->version = 1;
        $duplicate->status = 'draft';
        $duplicate->save();
        
        return $duplicate;
    }

    public function createNewVersion(): self
    {
        $newVersion = $this->replicate();
        $newVersion->version = $this->version + 1;
        $newVersion->status = 'draft';
        $newVersion->save();
        
        // Mark current version as retired
        $this->update(['status' => 'retired']);
        
        return $newVersion;
    }
}
