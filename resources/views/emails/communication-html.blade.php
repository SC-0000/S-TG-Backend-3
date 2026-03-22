@extends('emails.layout')

@section('title', $emailSubject ?? 'Notification')

@section('content')
    @if($bodyHtml)
        {!! $bodyHtml !!}
    @else
        {!! nl2br(e($bodyText)) !!}
    @endif
@endsection
