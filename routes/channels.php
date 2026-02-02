<?php

use Illuminate\Support\Facades\Broadcast;
use App\Models\LiveLessonSession;

Broadcast::channel('App.Models.User.{id}', function ($user, $id) {
    return (int) $user->id === (int) $id;
});

// Live Lesson Session Channel
Broadcast::channel('live-session.{sessionId}', function ($user, $sessionId) {
    $session = LiveLessonSession::find($sessionId);
    
    if (!$session) {
        return false;
    }
    
    // Check if user is the teacher
    if ($session->teacher_id === $user->id) {
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
        return [
            'id' => $user->id,
            'name' => $user->name,
            'role' => 'student'
        ];
    }
    
    return false;
});
