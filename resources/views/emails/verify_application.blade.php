@extends('emails.layout')

@section('title', 'Email Verification - ' . ($brandName ?? config('app.name')))

@section('content')
    <p>Hello {{ $application->applicant_name }},</p>
    <p>Thank you for submitting your application. To complete the process, please verify your email address by clicking the link below:</p>
    <p style="text-align: center;">
        @component('emails.components.button', ['href' => rtrim($portalBaseUrl ?? config('app.frontend_url'), '/') . '/applications/verify/' . $application->verification_token, 'variant' => 'primary'])
            Verify Email Address
        @endcomponent
    </p>
    <p>If you did not submit an application, please ignore this email.</p>
    <p>Thank you!</p>
@endsection
