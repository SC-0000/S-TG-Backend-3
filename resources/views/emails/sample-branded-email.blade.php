@extends('emails.layout')

@section('title', 'Welcome - ' . ($brandName ?? config('app.name')))

@section('content')
    <h2>Welcome to {{ $brandName ?? config('app.name') }}!</h2>
    
    <p>Hello {{ $recipientName }},</p>
    
    <p>Thank you for joining our platform. We're excited to have you on board!</p>
    
    <p>
        This is a sample email demonstrating the branded email layout. Your organization's
        branding is automatically applied to all email communications.
    </p>
    
    <div style="text-align: center; margin: 30px 0;">
        @component('emails.components.button', ['href' => $actionUrl, 'variant' => 'primary'])
            Get Started
        @endcomponent
    </div>
    
    <p>If you have any questions, feel free to reach out to our support team.</p>
    
    <p>
        Best regards,<br>
        <strong>{{ $brandName ?? config('app.name') }} Team</strong>
    </p>
@endsection
