@extends('emails.layout')

@section('title', 'Reset Your Password - ' . ($brandName ?? config('app.name')))

@section('content')
    <p>Hello {{ $userName }},</p>
    <p>We received a request to reset your password. Click the button below to choose a new one.</p>

    @component('emails.components.card', ['title' => 'Reset your password'])
        <p>This link expires in {{ $expires }} minutes for your security.</p>
        <div style="text-align: center; margin-top: 20px;">
            @component('emails.components.button', ['href' => $resetUrl, 'variant' => 'primary'])
                Reset Password
            @endcomponent
        </div>
    @endcomponent

    <p>If you didn’t request this, you can safely ignore this email.</p>
    <p>Need help? Reach out to us at {{ $supportEmail ?? $contactEmail ?? 'support' }}.</p>
@endsection
