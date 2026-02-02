@extends('emails.layout')

@section('title', 'Assessment Report - Eleven Plus Tutor')

@section('content')
    <h1>Assessment Report</h1>

    <p>Hello {{ $submission->child->user->name }},</p>

    <p>Here is the report for {{ $submission->child->child_name }}'s assessment:</p>
    <p><strong>{{ $submission->assessment->title }}</strong></p>

    <p>Scored: <strong>{{ $submission->marks_obtained }}/{{ $submission->total_marks }}</strong></p>

    <p>Please find the detailed report attached.</p>

    <p>Thanks,<br />
    Eleven Plus Tutor</p>
@endsection
