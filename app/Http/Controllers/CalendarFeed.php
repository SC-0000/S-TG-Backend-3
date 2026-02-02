<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Spatie\IcalendarGenerator\components\{Calendar, Event};
use App\Models\{User, LiveLessonSession, Assessment, Child, Access};

class CalendarFeed extends Controller
{
    public function __invoke(string $token)
    {
        $parent = User::findOrFail(decrypt($token));

        // Get all children for this parent
        $allChildren = collect();
        if ($parent->role === 'admin') {
            $allChildren = Child::select(['id'])->get();
        } elseif ($parent->role !== null) {
            $allChildren = $parent->children()->select(['id'])->get();
        }

        $childIds = $allChildren->pluck('id')->all();

        // Use access table to determine live sessions and assessments for these children
        $accessRecords = Access::whereIn('child_id', $childIds)
            ->where('access', true)
            ->where('payment_status', 'paid')
            ->get();

        $liveSessionIds = collect();
        $assessmentIds = collect();
        
        foreach ($accessRecords as $access) {
            // Live Lesson Sessions (lesson_id and lesson_ids now point to LiveLessonSession)
            if ($access->lesson_id) {
                $liveSessionIds->push($access->lesson_id);
            }
            if ($access->lesson_ids) {
                foreach ((array) $access->lesson_ids as $lid) {
                    $liveSessionIds->push($lid);
                }
            }
            
            // NEW: Extract live_lesson_session_ids from metadata (courses and services with linked LiveLessonSessions)
            if ($access->metadata) {
                $metadata = is_string($access->metadata) 
                    ? json_decode($access->metadata, true) 
                    : $access->metadata;
                
                if (isset($metadata['live_lesson_session_ids']) && is_array($metadata['live_lesson_session_ids'])) {
                    foreach ($metadata['live_lesson_session_ids'] as $lsid) {
                        $liveSessionIds->push($lsid);
                    }
                }
            }
            
            // Assessments
            if ($access->assessment_id) {
                $assessmentIds->push($access->assessment_id);
            }
            if ($access->assessment_ids) {
                foreach ((array) $access->assessment_ids as $aid) {
                    $assessmentIds->push($aid);
                }
            }
        }
        
        $liveSessionIds = $liveSessionIds->unique()->values();
        $assessmentIds = $assessmentIds->unique()->values();

        // Fetch Live Lesson Sessions
        $liveSessions = LiveLessonSession::whereIn('id', $liveSessionIds)->get();
        
        // Fetch Assessments
        $assessments = Assessment::whereIn('id', $assessmentIds)->get();

        $cal = Calendar::create('Jenny Tutor – ' . $parent->name)
            ->refreshInterval(60);  // 60 minutes refresh

        // Add live session events
        foreach ($liveSessions as $session) {
            $startTime = $session->scheduled_start_time;
            // Calculate end time: use scheduled end time if available, otherwise add 1 hour
            $endTime = $session->scheduled_end_time ?? $startTime->copy()->addHour();
            
            $cal->event(
                Event::create($session->title)
                    ->uniqueIdentifier("live-session-{$session->id}@jennytutor.com")
                    ->sequence($session->updated_at->unix())
                    ->startsAt($startTime->utc())
                    ->endsAt($endTime->utc())
                    ->description($session->description ?: '')
            );
        }

        // Add assessment events
        foreach ($assessments as $a) {
            $cal->event(
                Event::create($a->title)
                    ->uniqueIdentifier("assessment-{$a->id}@jennytutor.com")
                    ->sequence($a->updated_at->unix())
                    ->startsAt($a->deadline->utc())
                    ->endsAt($a->deadline->utc()->addMinutes(30))
                    ->description($a->description ?: '')
            );
        }

        return response($cal->get())
            ->header('Content-Type', 'text/calendar; charset=utf-8');
    }
}
