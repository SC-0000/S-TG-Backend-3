@extends('emails.layout')

@section('title', 'Welcome — Set Up Your Account - ' . ($brandName ?? config('app.name')))

@section('content')
    <p>Hello {{ $userName }},</p>
    <p>Welcome to {{ $brandName ?? config('app.name') }}! Your application has been approved and your account is ready.</p>
    <p>To get started, please set up your password by clicking the button below:</p>
    <p style="text-align: center;">
        @component('emails.components.button', ['href' => $setupUrl, 'variant' => 'primary'])
            Set Up My Account
        @endcomponent
    </p>
    @component('emails.components.alert', ['variant' => 'info'])
        This link is valid for 48 hours and can only be used once. If it expires, you can request a new one via the password reset page.
    @endcomponent
    <p>Once you've set your password, you'll be able to log in and access your account straight away.</p>
    <p>If you have any questions, don't hesitate to get in touch.</p>
    <p>Thank you!</p>
@endsection
