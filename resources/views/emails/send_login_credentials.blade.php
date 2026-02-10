@extends('emails.layout')

@section('title', 'Your Login Credentials - ' . ($brandName ?? config('app.name')))

@section('content')
    <p>Hello {{ $userName }},</p>
    <p>Your account has been created successfully. You can now log in using the credentials below:</p>
    <p><strong>Email:</strong> {{ $userEmail }}</p>
    <p><strong>Password:</strong> {{ $password }}</p>
    <p>You can log in using the following link: <a href="{{ $loginUrl }}" style="color: #007bff;">Login</a></p>
    <p>If you have any questions or need help, feel free to reach out.</p>
    <p>Thank you!</p>
@endsection
