<?php

use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Log;
use App\Models\LiveLessonSession;

Broadcast::channel('App.Models.User.{id}', function ($user, $id) {
    return (int) $user->id === (int) $id;
});

// Live Lesson Session Channel
Broadcast::channel('live-session.{sessionId}', function ($user, $sessionId) {
    if (!$user) {
        Log::warning('[Broadcast] live-session auth: no user', ['session_id' => $sessionId]);
        return false;
    }

    $session = LiveLessonSession::find($sessionId);
    
    if (!$session) {
        Log::warning('[Broadcast] live-session auth: session not found', ['session_id' => $sessionId, 'user_id' => $user->id]);
        return false;
    }
    
    // Check if user is the teacher
    if ($session->teacher_id === $user->id) {
        Log::info('[Broadcast] live-session auth: teacher access', ['session_id' => $sessionId, 'user_id' => $user->id]);
        return [
            'id' => $user->id,
            'name' => $user->name,
            'role' => 'teacher'
        ];
    }
    
    // Check if user's child is a participant
    $childIds = $user->children->pluck('id')->toArray();
    $isParticipant = $session->participants()
        ->whereIn('child_id', $childIds)
        ->exists();
    
    if ($isParticipant) {
        Log::info('[Broadcast] live-session auth: parent access', ['session_id' => $sessionId, 'user_id' => $user->id, 'child_ids' => $childIds]);
        return [
            'id' => $user->id,
            'name' => $user->name,
            'role' => 'student'
        ];
    }
    
    Log::warning('[Broadcast] live-session auth: access denied', ['session_id' => $sessionId, 'user_id' => $user->id, 'child_ids' => $childIds]);
    return false;
});
