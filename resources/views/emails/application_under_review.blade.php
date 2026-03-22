@extends('emails.layout')

@section('title', 'Application Under Review - ' . ($brandName ?? config('app.name')))

@section('content')
    <p>Hello {{ $applicantName }},</p>
    <p>Thank you for verifying your email address. Your application to {{ $brandName ?? config('app.name') }} has been received and is now under review.</p>
    @component('emails.components.card', ['title' => 'What Happens Next'])
        <ul style="margin: 0; padding-left: 20px; line-height: 1.8;">
            <li>Our team will review your application within 48 hours</li>
            <li>You'll receive an email once a decision has been made</li>
            <li>If we need any additional information, we'll be in touch</li>
        </ul>
    @endcomponent
    <p>If you have any questions in the meantime, please don't hesitate to contact us.</p>
    <p>Thank you for your patience!</p>
@endsection
