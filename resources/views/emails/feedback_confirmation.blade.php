@extends('emails.layout')

@section('title', 'Feedback Confirmation - ' . ($brandName ?? config('app.name')))

@section('content')
    <p>Hello {{ $feedback->name }},</p>
    <p>Thank you for submitting your feedback. We have received the following message from you:</p>

    <blockquote>
        <p><strong>Message:</strong></p>
        <p>{{ $feedback->message }}</p>  <!-- This will render the feedback message -->
    </blockquote>

    <p>We will review your feedback and get back to you as soon as possible. If you have any additional questions, feel free to reach out.</p>

    <p>Thank you for your input!</p>
@endsection
