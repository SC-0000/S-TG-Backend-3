@extends('emails.layout')

@section('title', 'Your Login Credentials - ' . ($brandName ?? config('app.name')))

@section('content')
    <p>Hello {{ $userName }},</p>
    <p>Your account has been created successfully. You can now log in using the credentials below:</p>
    @component('emails.components.card', ['title' => 'Login Details'])
        <p><strong>Email:</strong> {{ $userEmail }}</p>
        <p><strong>Password:</strong> {{ $password }}</p>
        <div style="text-align: center; margin-top: 20px;">
            @component('emails.components.button', ['href' => $loginUrl, 'variant' => 'primary'])
                Login
            @endcomponent
        </div>
    @endcomponent
    <p>If you have any questions or need help, feel free to reach out.</p>
    <p>Thank you!</p>
@endsection
