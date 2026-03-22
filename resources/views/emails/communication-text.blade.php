@extends('emails.layout')

@section('title', $emailSubject ?? 'Notification')

@section('content')
    {!! nl2br(e($bodyText)) !!}
@endsection
