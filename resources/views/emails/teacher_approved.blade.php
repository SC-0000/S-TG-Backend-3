@extends('emails.layout')

@section('title', 'Application Approved - ' . ($brandName ?? config('app.name')))

@section('header-title', '🎉 Welcome Aboard!')
@section('header-subtitle', 'Your teacher application has been approved')

@section('content')
    <p>Hi <strong>{{ $user->name }}</strong>,</p>

    <p>Great news! Your teacher application has been <strong>approved</strong>.</p>

    <p>You can now log in to your account and start your journey as a teacher with us.</p>

    <p><strong>Login Details:</strong></p>
    <ul>
        <li>Email: {{ $user->email }}</li>
        <li>Password: The password you created during registration</li>
    </ul>

    <p style="text-align: center;">
        <a href="{{ route('login') }}" class="btn">Login to Your Account</a>
    </p>

    <p>We're excited to have you on our team!</p>

    <p>Best regards,<br>
    <strong>The {{ $brandName ?? config('app.name') }} Team</strong></p>
@endsection
