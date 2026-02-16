@extends('emails.layout')

@section('title', 'Email Verification - ' . ($brandName ?? config('app.name')))

@section('content')
    <p>Hello {{ $application->applicant_name }},</p>
    <p>Thank you for submitting your application. To complete the process, please verify your email address by clicking the link below:</p>
    <p><a href="{{ rtrim(config('app.frontend_url'), '/') . '/applications/verify/' . $application->verification_token }}" style="padding: 10px 20px; margin: 10px 0; background-color: #007bff; color: white; text-decoration: none; border-radius: 5px;">Verify Email Address</a></p>
    <p>If you did not submit an application, please ignore this email.</p>
    <p>Thank you!</p>
@endsection
