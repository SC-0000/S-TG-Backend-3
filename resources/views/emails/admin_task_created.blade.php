@extends('emails.layout')

@section('title', 'New Admin Task - ' . ($brandName ?? config('app.name')))

@section('content')
    <p>Hello,</p>
    <p>A new admin task has been created for your organization.</p>

    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin: 20px 0; border-collapse: collapse;">
        <tr>
            <td style="padding: 8px 0; font-weight: 600; width: 140px;">Task Type:</td>
            <td style="padding: 8px 0;">{{ $task->task_type }}</td>
        </tr>
        @if(!empty($task->title))
        <tr>
            <td style="padding: 8px 0; font-weight: 600;">Title:</td>
            <td style="padding: 8px 0;">{{ $task->title }}</td>
        </tr>
        @endif
        @if(!empty($task->priority))
        <tr>
            <td style="padding: 8px 0; font-weight: 600;">Priority:</td>
            <td style="padding: 8px 0;">{{ $task->priority }}</td>
        </tr>
        @endif
        @if(!empty($task->status))
        <tr>
            <td style="padding: 8px 0; font-weight: 600;">Status:</td>
            <td style="padding: 8px 0;">{{ $task->status }}</td>
        </tr>
        @endif
        <tr>
            <td style="padding: 8px 0; font-weight: 600;">Created:</td>
            <td style="padding: 8px 0;">{{ optional($task->created_at)->format('M d, Y g:i A') }}</td>
        </tr>
    </table>

    @if(!empty($task->description))
        <p><strong>Description:</strong></p>
        <p>{{ $task->description }}</p>
    @endif

    @if(!empty($task->related_entity))
        <p style="margin-top: 24px;">
            <a href="{{ $task->related_entity }}" class="btn">View Task</a>
        </p>
    @endif

    <p style="margin-top: 24px;">Thank you.</p>
@endsection
