<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Assessment extends Model
{
    protected $fillable = [
        'title',
        'year_group',
        'description',
        'lesson_id',
        'type',
        'status',
        'availability',
        'journey_category_id',
        'deadline',
        'time_limit',
        'retake_allowed',
        'questions_json',
        'organization_id',
        'is_global',
    ];
     protected $casts = [
      'availability' => 'datetime',
      'deadline'     => 'datetime',
      'questions_json' => 'array',    
      'is_global' => 'boolean',
    ];

    public function lesson()
    {
        return $this->belongsTo(LiveLessonSession::class, 'lesson_id');
    }

    public function category() {
        return $this->belongsTo(JourneyCategory::class,'journey_category_id');
    }

    public function submissions()
    {
        return $this->hasMany(AssessmentSubmission::class);
    }

    public function notifications()
    {
        return $this->hasMany(AssessmentNotification::class);
    }
    public function service()
    {
        return $this->belongsToMany(
        Service::class,
        'assessment_service',   // your pivot table
        'assessment_id',
        'service_id'
    );
    }
    public function scopeForParent($q, int $parentId)
    {
        return $q->whereHas('service.children', fn ($c) =>
                   $c->whereHas('user', fn ($u) => $u->where('users.id', $parentId))
               );
    }

    public function scopeForTeacher($query, $teacherId)
    {
        return $query->where(function ($q) use ($teacherId) {
            // Assessments linked to lessons taught by this teacher
            $q->whereHas('lesson', function ($lessonQuery) use ($teacherId) {
                $lessonQuery->where('teacher_id', $teacherId);
            });
        });
    }

    public function scopeGlobal($query)
    {
        return $query->where('is_global', true);
    }

    public function scopeVisibleToOrg($query, ?int $organizationId)
    {
        return $query->where(function ($q) use ($organizationId) {
            $q->where('is_global', true);
            if ($organizationId) {
                $q->orWhere('organization_id', $organizationId);
            }
        });
    }
 public function getQuestionsAttribute()
    {
        return $this->questions_json ?? [];
    }
     public function questionsByCategory(string $category)
    {
        return array_filter($this->questions, fn($q) =>
            isset($q['category']) && $q['category'] === $category
        );
    }

    // New relationships for question bank integration
    public function bankQuestions()
    {
        return $this->belongsToMany(Question::class, 'assessment_question_bank')
                    ->withPivot('order_position', 'custom_points', 'custom_settings')
                    ->withTimestamps()
                    ->orderBy('pivot_order_position');
    }

    /**
     * Relationship with inline questions (existing functionality)
     */
    public function inlineQuestions()
    {
        return $this->hasMany(AssessmentQuestion::class);
    }

    public function organization()
    {
        return $this->belongsTo(Organization::class);
    }

    /**
     * Many-to-many relationship with modules
     */
    public function modules()
    {
        return $this->belongsToMany(Module::class, 'assessment_module')
                    ->withPivot('timing')
                    ->withTimestamps();
    }

    // Combined questions method - returns both inline and bank questions
    public function getAllQuestions()
    {
        $inlineQuestions = $this->questions_json ?? [];
        $bankQuestions = $this->bankQuestions()->get()->map(function ($question) {
            return [
                'id' => $question->id,
                'type' => 'bank_question',
                'question_id' => $question->id,
                'title' => $question->title,
                'question_text' => $question->question_data['question_text'] ?? '',
                'question_type' => $question->question_type,
                'marks' => $question->pivot->custom_points ?? $question->marks,
                'category' => $question->category,
                'difficulty_level' => $question->difficulty_level,
                'estimated_time_minutes' => $question->estimated_time_minutes,
                'tags' => $question->tags,
                'question_data' => $question->question_data,
                'answer_schema' => $question->answer_schema,
                'order_position' => $question->pivot->order_position,
                'custom_settings' => $question->pivot->custom_settings,
            ];
        })->toArray();

        // Mark inline questions with type and add order positions
        $inlineQuestions = array_map(function ($question, $index) {
            // Preserve the original question type (mcq, essay, etc.) before overwriting
            if (isset($question['type'])) {
                $question['question_type'] = $question['type'];
            }
            $question['type'] = 'inline_question';
            $question['order_position'] = $index + count($this->bankQuestions);
            return $question;
        }, $inlineQuestions, array_keys($inlineQuestions));

        // Combine and sort by order position
        $allQuestions = array_merge($bankQuestions, $inlineQuestions);
        usort($allQuestions, fn($a, $b) => $a['order_position'] <=> $b['order_position']);

        return $allQuestions;
    }

}
