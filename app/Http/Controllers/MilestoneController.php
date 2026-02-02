<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Milestone;
use Inertia\Inertia;

class MilestoneController extends Controller
{
    public function index()
    {
        // Authorization would normally be here (e.g. // abort_if(!auth()->user()->can('view', Milestone::class))
        $orgId = auth()->user()?->current_organization_id;
        $milestones = Milestone::when($orgId, fn($q) => $q->forOrganization($orgId))
            ->orderBy('DisplayOrder')
            ->get();
        return Inertia::render('@admin/Milestones/Index', compact('milestones'));
    }

    public function create()
    {
        // Authorization would be here if needed.
        return Inertia::render('@admin/Milestones/Create');
    }

    public function store(Request $request)
    {
        $validatedData = $request->validate([
            'Title'         => 'required|string|max:255',
            'Date'          => 'required|date',
            'Description'   => 'required|string',
            'Image'         => 'nullable|image|max:2048', // image file input
            'DisplayOrder'  => 'nullable|integer',
        ]);

        if ($request->hasFile('Image')) {
            // Store the image under "milestones" folder on the public disk.
            $validatedData['Image'] = $request->file('Image')->store('milestones', 'public');
        }

        $validatedData['organization_id'] = $request->user()?->current_organization_id;
        Milestone::create($validatedData);

        return redirect()->route('milestones.index')
                         ->with('success', 'Milestone created successfully!');
    }

    public function edit($id)
    {
        // Authorization would normally be here.
        $orgId = auth()->user()?->current_organization_id;
        $milestone = Milestone::when($orgId, fn($q) => $q->forOrganization($orgId))->findOrFail($id);
        return Inertia::render('@admin/Milestones/Edit', compact('milestone'));
    }

    public function update(Request $request, $id)
    {
        $orgId = auth()->user()?->current_organization_id;
        $milestone = Milestone::when($orgId, fn($q) => $q->forOrganization($orgId))->findOrFail($id);

        $validatedData = $request->validate([
            'Title'         => 'required|string|max:255',
            'Date'          => 'required|date',
            'Description'   => 'required|string',
            'Image'         => 'nullable|image|max:2048', // only validate image if a new file is uploaded
            'DisplayOrder'  => 'nullable|integer',
        ]);

        if ($request->hasFile('Image')) {
            $validatedData['Image'] = $request->file('Image')->store('milestones', 'public');
        }
        // If no new file is uploaded, the existing image is retained.

        $milestone->update($validatedData);

        return redirect()->route('milestones.index')
                         ->with('success', 'Milestone updated successfully!');
    }
}
