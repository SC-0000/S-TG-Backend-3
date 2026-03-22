@extends('emails.layout')

@section('title', 'Payment Failed')

@section('header-title')
    Payment Unsuccessful
@endsection

@section('header-subtitle')
    We were unable to process your payment
@endsection

@section('content')
    <h1>Payment Failed</h1>

    <p>Hello{{ optional($transaction->user)->name ? ', <strong>' . optional($transaction->user)->name . '</strong>' : '' }},</p>

    <p>We were unable to process your payment of <strong>&pound;{{ $amount }}</strong> for transaction <strong>#{{ $transaction->id }}</strong>.</p>

    <div style="background-color: #fef2f2; border: 1px solid #fecaca; border-radius: 8px; padding: 16px; margin: 20px 0;">
        <p style="color: #991b1b; margin: 0;">
            <strong>Reason:</strong> {{ $userMessage }}
        </p>
    </div>

    <p>You can retry your payment or update your payment method by clicking the button below:</p>

    <div style="text-align: center; margin: 30px 0;">
        <a href="{{ url('/billing/portal') }}"
           style="display: inline-block; padding: 12px 30px; background-color: {{ $brandingColors['primary'] ?? '#4F46E5' }}; color: #ffffff; text-decoration: none; border-radius: 6px; font-weight: 600;">
            Retry Payment
        </a>
    </div>

    <p>If you continue to experience issues, please contact us for assistance.</p>
@endsection
