@extends('emails.layout')

@section('title', $subject ?? ($brandName ?? 'Campaign'))

@section('content')
    @if(!empty($recipientName))
        <p style="margin: 0 0 18px 0;">Hi {{ $recipientName }},</p>
    @endif

    {!! $contentHtml ?? '' !!}

    @if(!empty($unsubscribeUrl))
        <hr style="border: none; border-top: 1px solid #e5e7eb; margin: 28px 0;">
        <p style="font-size: 12px; color: #6b7280; margin: 0;">
            You can unsubscribe at any time:
            <a href="{{ $unsubscribeUrl }}" style="color: {{ $emailButtonColor }}; text-decoration: underline;">
                Unsubscribe
            </a>
        </p>
    @endif
@endsection
