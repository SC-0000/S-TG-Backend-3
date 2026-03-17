<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreServiceRequest extends FormRequest
{
    // public function authorize(): bool
    // {
    //     return $this->user()->can('manage-services');
    // }
    public function authorize(): bool
{
    return true;
}

    public function rules(): array
    {
        return [
            'service_name'      => 'required|string|max:255',
            '_type'             => 'required|in:lesson,assessment,bundle,course,flexible',
            'booking_mode'      => 'nullable|in:fixed_schedule,flexible_booking,self_paced,none',
            'service_level'     => 'required|in:basic,full_membership',
            'availability'      => 'boolean',
            'is_global'         => 'nullable|boolean',
            'organization_id'   => 'nullable|integer|exists:organizations,id',
            'price'             => 'nullable|numeric|min:0',
            'start_datetime'    => 'nullable|date',
            'end_datetime'      => 'nullable|date|after_or_equal:start_datetime',
            'lesson_ids'        => 'array',
            'lesson_ids.*'      => 'integer|exists:live_sessions,id',
            'assessment_ids'    => 'array',
            'assessment_ids.*'  => 'integer|exists:assessments,id',
            'child_ids'         => 'array',
            'child_ids.*'       => 'integer|exists:children,id',
            'course_id'         => 'nullable|integer|exists:courses,id',
            'quantity'                 => 'nullable|integer|min:0',
            'quantity_remaining'       => 'nullable|integer|min:0',
            'quantity_allowed_per_child' => 'nullable|integer|min:1',
            'restriction_type'         => 'nullable|in:All,YearGroup,Specific',
            'year_groups_allowed'      => 'nullable|array',
            'categories'               => 'nullable|array',
            'auto_attendance'          => 'nullable|boolean',
            'display_until'            => 'nullable|date',
            'description'              => 'nullable|string',
            'schedule'                 => 'nullable',
            'media'                    => 'nullable',

            // Booking mode fields
            'session_duration_minutes' => 'nullable|integer|min:15|max:480',
            'max_participants'         => 'nullable|integer|min:1',
            'teacher_ids'              => 'nullable|array',
            'teacher_ids.*'            => 'integer|exists:users,id',
            'allow_recurring'          => 'nullable|boolean',
            'cancellation_hours'       => 'nullable|integer|min:0',
            'credits_per_purchase'     => 'nullable|integer|min:1',

            // Flexible service fields
            'selection_config'                      => 'nullable|array',
            'selection_config.live_sessions'        => 'nullable|integer|min:0',
            'selection_config.assessments'          => 'nullable|integer|min:0',
            'flexible_content'                      => 'nullable|array',
            'flexible_content.*.id'                 => 'required|integer',
            'flexible_content.*.type'               => 'required|in:lesson,assessment',
            'flexible_content.*.max_enrollments'    => 'nullable|integer|min:1',
        ];
    }
}
