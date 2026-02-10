@extends('emails.layout')

@section('title', 'Application Received - ' . ($brandName ?? config('app.name')))

@section('header-title', 'Application Received')
@section('header-subtitle', 'We have received your application')

@section('content')
    <p>Hi <strong>{{ $name }}</strong>,</p>

    <p>Thank you for applying to become a teacher with us!</p>

    <p>Your application has been successfully received and is now under review by our admin team.</p>

    <p>We will notify you via email once your application has been reviewed.</p>

    <p>If you have any questions in the meantime, please don't hesitate to contact us at
        <a href="mailto:{{ $supportEmail ?? config('mail.from.address') }}">{{ $supportEmail ?? config('mail.from.address') }}</a>.</p>

    <p>Best regards,<br>
    <strong>The {{ $brandName ?? config('app.name') }} Team</strong></p>
@endsection
