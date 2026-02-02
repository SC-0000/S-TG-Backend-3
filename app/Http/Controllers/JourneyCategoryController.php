<?php

namespace App\Http\Controllers;

use App\Models\Journey;
use App\Models\JourneyCategory;
use Illuminate\Http\Request;
use Inertia\Inertia;

class JourneyCategoryController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
       $orgId = auth()->user()?->current_organization_id;
       $categories = JourneyCategory::with('journey')
        ->when($orgId, fn($q) => $q->forOrganization($orgId))
        ->get();
       return Inertia::render('@admin/JourneyCategories/Index', compact('categories'));
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {
          $orgId = auth()->user()?->current_organization_id;
          $journeys = Journey::when($orgId, fn($q) => $q->forOrganization($orgId))->orderBy('title')->get();
         return Inertia::render('@admin/JourneyCategories/Create', compact('journeys'));
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $r)
    {
        $r->validate([
        'journey_id'=>'required|exists:journeys,id',
        'topic'=>'required|string|max:255',
        'name'=>'required|string|max:255',
        'description'=>'nullable|string',
        ]);
        JourneyCategory::create([
            'organization_id' => $r->user()?->current_organization_id,
            'journey_id' => $r->journey_id,
            'topic' => $r->topic,
            'name' => $r->name,
            'description' => $r->description,
        ]);
        return redirect()->route('journey-categories.index');
    
    }

    /**
     * Display the specified resource.
     */
    public function show(JourneyCategory $journeyCategory)
    {
        //
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(JourneyCategory $journeyCategory)
    {
        $orgId = auth()->user()?->current_organization_id;
        abort_unless(!$orgId || $journeyCategory->organization_id === $orgId, 403);
        return Inertia::render('@admin/JourneyCategories/Edit', compact('journeyCategory'));
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, JourneyCategory $journeyCategory)
    {
        $orgId = auth()->user()?->current_organization_id;
        abort_unless(!$orgId || $journeyCategory->organization_id === $orgId, 403);
        //
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(JourneyCategory $journeyCategory)
    {
        $orgId = auth()->user()?->current_organization_id;
        abort_unless(!$orgId || $journeyCategory->organization_id === $orgId, 403);
        //
    }
}
