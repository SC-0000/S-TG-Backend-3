<?php

namespace App\Http\Controllers;

use App\Models\Service;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;

class PublicServiceController extends Controller
{
    public function portalShow(Service $service)
    {
        /*
        |------------------------------------------------------------------
        | 1. Eager-load relations – only columns that really exist!
        |------------------------------------------------------------------
        */
        $service->load([
            // LESSONS **do** have service_id
            'lessons:id,service_id,title,start_time,end_time,lesson_mode,address,meeting_link',

            // ASSESSMENTS come from a pivot – no service_id column here
            'assessments:id,title,description,deadline',

            'children:id,child_name,year_group',
        ]);
        Log::debug('Service loaded public service controller', ['service' => $service->toArray()]);
        /*
        |------------------------------------------------------------------
        | 2. Build a single chronological timeline (lessons + assessments)
        |------------------------------------------------------------------
        */
        $timeline = collect();

        foreach ($service->lessons as $lesson) {
            $timeline->push([
                'type'  => 'lesson',
                'id'    => $lesson->id,
                'title' => $lesson->title,
                'at'    => $lesson->start_time,
                'extra' => [
                    'end'   => $lesson->end_time,
                    'mode'  => $lesson->lesson_mode,
                    'place' => $lesson->address,
                    'link'  => $lesson->meeting_link,
                ],
            ]);
        }

        foreach ($service->assessments as $assessment) {
            $timeline->push([
                'type'  => 'assessment',
                'id'    => $assessment->id,
                'title' => $assessment->title,
                'at'    => $assessment->deadline,
                'extra' => [
                    'desc' => $assessment->description,
                ],
            ]);
        }

        $timeline = $timeline->sortBy('at')->values();
        /*
        |------------------------------------------------------------------
        | 3. Ship everything to Inertia
        |------------------------------------------------------------------
        */
        return Inertia::render('@public/Services/ShowPublic', [
            'service'  => $service,
            'timeline' => $timeline,
        ]);
    }
}
