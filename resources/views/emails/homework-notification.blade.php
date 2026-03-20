@extends('emails.layout')

@section('title', $titleText ?? 'Homework Update')

@section('content')
    <p style="font-size: 16px; color: #1f2937; margin-bottom: 12px;">
        {{ $messageText ?? 'You have a homework update.' }}
    </p>

    @if(!empty($childName))
        <p style="font-size: 14px; color: #6b7280; margin-bottom: 20px;">
            Student: <strong>{{ $childName }}</strong>
        </p>
    @endif

    @if(!empty($actionUrl))
        @component('emails.components.button', ['href' => $actionUrl])
            {{ $actionLabel ?? 'View Homework' }}
        @endcomponent
    @endif
@endsection
