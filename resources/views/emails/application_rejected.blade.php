@extends('emails.layout')

@section('title', 'Application Update - ' . ($brandName ?? config('app.name')))

@section('content')
    <p>Hello {{ $applicantName }},</p>
    <p>Thank you for your interest in {{ $brandName ?? config('app.name') }}. After reviewing your application, we are unfortunately unable to approve it at this time.</p>
    @if($feedback)
        @component('emails.components.card', ['title' => 'Feedback'])
            <p>{{ $feedback }}</p>
        @endcomponent
    @endif
    <p>If you believe this decision was made in error, or if your circumstances have changed, you are welcome to submit a new application.</p>
    <p style="text-align: center;">
        @component('emails.components.button', ['href' => $contactUrl, 'variant' => 'secondary'])
            Contact Us
        @endcomponent
    </p>
    <p>Thank you for your understanding.</p>
@endsection
