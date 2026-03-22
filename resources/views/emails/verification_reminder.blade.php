@extends('emails.layout')

@section('title', 'Verify Your Email - ' . ($brandName ?? config('app.name')))

@section('content')
    <p>Hello {{ $applicantName }},</p>
    <p>We noticed that you submitted an application to {{ $brandName ?? config('app.name') }} but haven't verified your email address yet.</p>
    <p>Please click the button below to verify your email and complete your application:</p>
    <p style="text-align: center;">
        @component('emails.components.button', ['href' => $verifyUrl, 'variant' => 'primary'])
            Verify Email Address
        @endcomponent
    </p>
    @component('emails.components.alert', ['variant' => 'warning'])
        Your application cannot be processed until your email is verified.
    @endcomponent
    <p>If you did not submit an application, please ignore this email.</p>
    <p>Thank you!</p>
@endsection
