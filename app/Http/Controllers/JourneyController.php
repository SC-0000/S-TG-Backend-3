<?php

namespace App\Http\Controllers;

use App\Models\Child;
use App\Models\Journey;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;

class JourneyController extends Controller
{
   public function index() {
        $orgId = auth()->user()?->current_organization_id;
        $journeys = Journey::when($orgId, fn($q) => $q->forOrganization($orgId))
            ->orderBy('title')
            ->get();
        return Inertia::render('@admin/Journeys/Index', compact('journeys'));
    }
  public function create() {
        return Inertia::render('@admin/Journeys/Create');
    }
  public function store(Request $r) {
        $r->validate([
        'title'=>'required|string|max:255',
        'description'=>'nullable|string',
        'exam_end_date'=>'nullable|date',
        ]);
        Journey::create([
            'organization_id' => $r->user()?->current_organization_id,
            'title' => $r->title,
            'description' => $r->description,
            'exam_end_date' => $r->exam_end_date,
        ]);
        return redirect()->route('journeys.index');
    }
    
  
    /**
     * Display the specified resource.
     */
    public function show()
    { 
        $orgId = auth()->user()?->current_organization_id;
        $journeys = Journey::when($orgId, fn($q) => $q->forOrganization($orgId))
        ->with([
            'categories' => fn ($q) => $q->with([
                'lessons:id,title,journey_category_id',
                'assessments:id,title,journey_category_id',
            ])->orderBy('topic')->orderBy('name'),
        ])->orderBy('title')->get();

        // 2) Reshape: group each journey’s categories by TOPIC
        $journeys = $journeys->map(function (Journey $j) {
            /** @var Collection $byTopic */
            $byTopic = $j->categories
                ->groupBy('topic')
                ->map(function ($cats) {
                    return $cats->map(function ($cat) {
                        return [
                            'id'          => $cat->id,
                            'name'        => $cat->name,
                            'lessons'     => $cat->lessons->map->only(['id', 'title']),
                            'assessments' => $cat->assessments->map->only(['id', 'title']),
                        ];
                    })->values();
                });

            return [
                'id'      => $j->id,
                'title'   => $j->title,
                'topics'  => $byTopic,   // key = topic string, value = [ {category,…}, … ]
            ];
        });

        return Inertia::render('@admin/Journeys/Show', [
            'journeys' => $journeys,
        ]);
    }
    public function portalOverview(){
        $orgId = auth()->user()?->current_organization_id;
         $journeys = Journey::when($orgId, fn($q) => $q->forOrganization($orgId))
        ->with([
            'categories' => fn ($q) => $q->with([
                'lessons:id,title,journey_category_id',
                'assessments:id,title,journey_category_id',
            ])->orderBy('topic')->orderBy('name'),
        ])->orderBy('title')->get();

        // 2) Reshape: group each journey’s categories by TOPIC
        $journeys = $journeys->map(function (Journey $j) {
            /** @var Collection $byTopic */
            $byTopic = $j->categories
                ->groupBy('topic')
                ->map(function ($cats) {
                    return $cats->map(function ($cat) {
                        return [
                            'id'          => $cat->id,
                            'name'        => $cat->name,
                            'lessons'     => $cat->lessons->map->only(['id', 'title']),
                            'assessments' => $cat->assessments->map->only(['id', 'title']),
                        ];
                    })->values();
                });
                                
            return [
                'id'      => $j->id,
                'title'   => $j->title,
                'topics'  => $byTopic,   // key = topic string, value = [ {category,…}, … ]
            ];
        });
        $user = auth()->user();
        $childrenData = [];

        if ($user->role == 'admin') {
            // Admin: get all children
            $children = Child::with('services.lessons', 'services.assessments')->get();
        } else {
            // Parent: only their children
            $children = $user->children()->with('services.lessons', 'services.assessments')->get();
        }

        foreach ($children as $child) {
            $lessonIds = collect();
            $assessmentIds = collect();

            foreach ($child->services as $service) {
                $lessonIds = $lessonIds->merge($service->lessons->pluck('id'));
                $assessmentIds = $assessmentIds->merge($service->assessments->pluck('id'));
            }

            $childrenData[] = [
                'child_id' => $child->id,
                'lesson_ids' => $lessonIds->unique()->values(),
                'assessment_ids' => $assessmentIds->unique()->values(),
            ];
        }

        Log::info('Children Data: ', $childrenData);
        return Inertia::render('@parent/Journeys/Overview', [
            'journeys' => $journeys,
            'childrenData' => $childrenData,
        ]);
    }



    /**
     * Show the form for editing the specified resource.
     */
    public function edit(Journey $journey)
    {
        return Inertia::render('@admin/Journeys/Edit', [
            'journey' => $journey,
        ]);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, Journey $journey)
    {
        //
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Journey $journey)
    {
        //
    }
}
