@extends('emails.layout')

@section('title', 'Email Verification Code')

@section('header-title', 'Email Verification')
@section('header-subtitle', 'Secure access to your account')

@section('content')
    <h1 style="text-align: center; margin-bottom: 30px;">🔐 Your Verification Code</h1>
    
    <p>Hello{{ isset($email) ? ', <strong>' . e($email) . '</strong>' : '' }},</p>
    
    <p>We received a request to verify your email address for quick checkout access. Use the verification code below to continue with your purchase.</p>
    
    @component('emails.components.card', ['type' => 'info'])
        <div style="text-align: center; padding: 20px 0;">
            <h2 style="margin: 0 0 15px 0; color: #2563eb;">Verification Code</h2>
            <div style="display: inline-block; font-size: 36px; letter-spacing: 8px; padding: 20px 30px; background: linear-gradient(135deg, #eff6ff 0%, #dbeafe 100%); border: 2px solid #2563eb; border-radius: 12px; font-weight: 700; color: #1e40af; font-family: 'Courier New', monospace;">
                {{ $code }}
            </div>
            <p style="margin: 15px 0 0 0; color: #6b7280; font-size: 14px;">
                <strong>⏰ This code expires in 10 minutes</strong>
            </p>
        </div>
    @endcomponent
    
    @component('emails.components.alert', ['type' => 'warning'])
        <strong>Security Notice:</strong> If you didn't request this verification code, please ignore this email. Your account security is not compromised.
    @endcomponent
    
    @component('emails.components.spacer', ['height' => '30px'])
    @endcomponent
    
    <h3>🛒 What's Next?</h3>
    <ol style="padding-left: 20px; line-height: 1.8;">
        <li>Enter the verification code on the checkout page</li>
        <li>Complete your purchase securely</li>
        <li>Receive instant access to your purchased content</li>
    </ol>
    
    @component('emails.components.divider')
    @endcomponent
    
    <p style="text-align: center; color: #6b7280; font-size: 14px;">
        <strong>Need help?</strong> Contact our support team at <a href="mailto:{{ $supportEmail ?? config('mail.from.address') }}">{{ $supportEmail ?? config('mail.from.address') }}</a>
    </p>
@endsection
