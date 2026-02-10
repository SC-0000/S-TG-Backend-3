Assessment Report

Hello {{ $submission->child->user->name }},

Here is the report for {{ $submission->child->child_name }}'s assessment:
{{ $submission->assessment->title }}

Scored: {{ $submission->marks_obtained }}/{{ $submission->total_marks }}

Please find the detailed report attached.

Thanks,
{{ $brandName ?? config('app.name') }}
