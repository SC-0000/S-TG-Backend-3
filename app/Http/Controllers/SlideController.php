<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Slide;
use Inertia\Inertia;
use Illuminate\Support\Str;
use Carbon\Carbon;

class SlideController extends Controller
{
    public function create()
    {
        // Render the Inertia component for creating a slide.
        return Inertia::render('@admin/Slides/CreateSlide');
    }

    public function store(Request $request)
    {
        // Validate required fields. For fields that are provided as text (comma separated),
        // we'll convert them to arrays.
        $validatedData = $request->validate([
            'title'       => 'required|string|max:255',
            'content'     => 'required|string', // User enters structured text; you may later convert it as needed.
            'template_id' => 'nullable|string', // Comma separated values.
            'order'       => 'required|integer',
            'tags'        => 'nullable|string', // Comma separated tags.
            'schedule'    => 'nullable|string', // Comma separated start/end or a structured string.
            'status'      => 'required|string|in:active,draft,archived',
            'version'     => 'required|integer',
            // We'll process images separately as file uploads.
        ]);

        // Convert comma-separated string fields to arrays.
        foreach (['template_id', 'tags', 'schedule'] as $field) {
            if (!empty($validatedData[$field])) {
                $validatedData[$field] = array_map('trim', explode(',', $validatedData[$field]));
            } else {
                $validatedData[$field] = null;
            }
        }

        // Process file uploads for images if provided.
        $imagePaths = [];
        if ($request->hasFile('images')) {
            foreach ($request->file('images') as $image) {
                // Store each image in the "slides" directory in storage/app/public/slides.
                // Make sure you have run "php artisan storage:link" to link public storage.
                $path = $image->store('slides', 'public');
                $imagePaths[] = $path;
            }
        }
        $validatedData['images'] = $imagePaths ?: null;

        // Set additional fields.
        $validatedData['slide_id'] = (string) Str::uuid();
        $validatedData['last_modified'] = Carbon::now();
        $validatedData['organization_id'] = $request->user()?->current_organization_id;
        // Set created_by from the authenticated user (or a default value).
        // $validatedData['created_by'] = auth()->id() ?? (string) Str::uuid();
        
        // Create the slide.
        Slide::create($validatedData);

        return redirect()->route('slides.index')
                         ->with('success', 'Slide created successfully!');
    }
    // List all slides.
public function index()
{
    $orgId = auth()->user()?->current_organization_id;
    $slides = Slide::when($orgId, fn($q) => $q->forOrganization($orgId))->get();
    return Inertia::render('@admin/Slides/IndexSlide', ['slides' => $slides]);
}

// Display a single slide.
public function show($slide_id)
{
    $orgId = auth()->user()?->current_organization_id;
    $slide = Slide::when($orgId, fn($q) => $q->forOrganization($orgId))->findOrFail($slide_id);
    return Inertia::render('@admin/Slides/ShowSlide', ['slide' => $slide]);
}

// Show form to edit an existing slide.
public function edit($slide_id)
{
    $orgId = auth()->user()?->current_organization_id;
    $slide = Slide::when($orgId, fn($q) => $q->forOrganization($orgId))->findOrFail($slide_id);
    return Inertia::render('@admin/Slides/EditSlide', ['slide' => $slide]);
}

// Update an existing slide.
public function update(Request $request, $slide_id)
{
    $orgId = auth()->user()?->current_organization_id;
    $slide = Slide::when($orgId, fn($q) => $q->forOrganization($orgId))->findOrFail($slide_id);

    $validatedData = $request->validate([
        'title'       => 'required|string|max:255',
        'content'     => 'required|string',
        'template_id' => 'nullable|string',
        'order'       => 'required|integer',
        'tags'        => 'nullable|string',
        'schedule'    => 'nullable|string',
        'status'      => 'required|string|in:active,draft,archived',
        'version'     => 'required|integer',
        // Add image validation if needed, e.g., 'nullable|image|max:2048'
    ]);

    // Convert comma-separated fields to arrays.
    foreach (['template_id', 'tags', 'schedule'] as $field) {
        if (!empty($validatedData[$field])) {
            $validatedData[$field] = array_map('trim', explode(',', $validatedData[$field]));
        } else {
            $validatedData[$field] = null;
        }
    }

    // Process file uploads if provided.
    if ($request->hasFile('images')) {
        $imagePaths = [];
        foreach ($request->file('images') as $image) {
            $path = $image->store('slides', 'public');
            $imagePaths[] = $path;
        }
        $validatedData['images'] = $imagePaths;
    } else {
        $validatedData['images'] = $slide->images; // Retain existing images.
    }

    $validatedData['last_modified'] = \Carbon\Carbon::now();

    $slide->update($validatedData);

    return redirect()->route('slides.show', $slide->slide_id)
                     ->with('success', 'Slide updated successfully!');
}

// (Optional) Delete slide.
public function destroy($slide_id)
{
    $orgId = auth()->user()?->current_organization_id;
    $slide = Slide::when($orgId, fn($q) => $q->forOrganization($orgId))->findOrFail($slide_id);
    $slide->delete();

    return redirect()->route('slides.index')
                     ->with('success', 'Slide deleted successfully!');
}

}
