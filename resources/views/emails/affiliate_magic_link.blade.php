@extends('emails.layout')

@section('title', 'Login Link - ' . ($brandName ?? config('app.name')))

@section('content')
    <p>Hello {{ $affiliate->name }},</p>
    <p>Click the button below to log in to your affiliate dashboard. This link is valid for 15 minutes and can only be used once.</p>
    <p style="text-align: center;">
        @component('emails.components.button', ['href' => $magicUrl, 'variant' => 'primary'])
            Log In to Dashboard
        @endcomponent
    </p>
    <p style="font-size: 13px; color: #6b7280;">If you did not request this login link, you can safely ignore this email. Do not share this link with anyone.</p>
@endsection
