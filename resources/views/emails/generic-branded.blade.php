@extends('emails.layout')

@section('title', $subject ?? 'Notification')

@section('content')
    {!! nl2br(e($body)) !!}
@endsection
