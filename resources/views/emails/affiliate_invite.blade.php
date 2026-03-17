@extends('emails.layout')

@section('title', 'Affiliate Invitation - ' . ($brandName ?? config('app.name')))

@section('content')
    <p>Hello {{ $affiliate->name }},</p>
    <p>You have been invited to join <strong>{{ $brandName }}</strong> as an affiliate partner.</p>
    <p>As an affiliate, you can share your unique tracking links and earn commission on successful referrals.</p>
    <p style="text-align: center;">
        @component('emails.components.button', ['href' => $magicUrl, 'variant' => 'primary'])
            Access Your Dashboard
        @endcomponent
    </p>
    <p style="font-size: 13px; color: #6b7280;">This link is valid for 15 minutes. You can request a new login link at any time from the affiliate login page.</p>
@endsection
