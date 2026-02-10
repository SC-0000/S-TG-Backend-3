@extends('emails.layout')

@section('title', 'Application Update - ' . ($brandName ?? config('app.name')))

@section('header-title', 'Application Update')
@section('header-subtitle', 'Thank you for your interest')

@section('content')
    <p>Hi <strong>{{ $name }}</strong>,</p>

    <p>Thank you for your interest in joining our teaching team.</p>

    <p>After careful review, we regret to inform you that we are unable to approve your application at this time.</p>

    <p>We appreciate the time you took to apply and wish you the best in your future endeavors.</p>

    <p>If you have any questions, please feel free to contact us at
        <a href="mailto:{{ $supportEmail ?? config('mail.from.address') }}">{{ $supportEmail ?? config('mail.from.address') }}</a>.</p>

    <p>Best regards,<br>
    <strong>The {{ $brandName ?? config('app.name') }} Team</strong></p>
@endsection
