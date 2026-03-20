<?php

namespace App\Support;

use App\Models\Assessment;
use App\Models\Question;
use Illuminate\Support\Collection;

class HomeworkItemResolver
{
    public function resolve(Collection $items): array
    {
        return $items->map(function ($item) {
            $base = [
                'id' => $item->id,
                'homework_id' => $item->homework_id,
                'type' => $item->type,
                'ref_id' => $item->ref_id,
                'payload' => $item->payload,
                'sort_order' => $item->sort_order,
            ];

            if ($item->type === 'question_bank' && $item->ref_id) {
                $question = Question::find($item->ref_id);
                if ($question) {
                    $base['question'] = $question->renderForStudent();
                    $base['question_meta'] = [
                        'id' => $question->id,
                        'title' => $question->title,
                        'marks' => $question->marks,
                        'question_type' => $question->question_type,
                        'grade' => $question->grade,
                    ];
                }
            }

            if ($item->type === 'assessment' && $item->ref_id) {
                $assessment = Assessment::find($item->ref_id);
                if ($assessment) {
                    $base['assessment'] = [
                        'id' => $assessment->id,
                        'title' => $assessment->title,
                        'type' => $assessment->type,
                        'deadline' => $assessment->deadline?->toISOString(),
                        'status' => $assessment->status,
                    ];
                }
            }

            return $base;
        })->values()->all();
    }
}
