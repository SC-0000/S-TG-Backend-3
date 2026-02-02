<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Testimonial;
use Illuminate\Support\Facades\Log;

class TestimonialController extends Controller
{
    public function index()
    {
        // Authorization would go here (e.g., abort_if(!auth()->user()->can('view', Testimonial::class)))
        $orgId = auth()->user()?->current_organization_id;
        $testimonials = Testimonial::when($orgId, fn($q) => $q->forOrganization($orgId))
            ->orderBy('DisplayOrder')
            ->get();
        return inertia('@admin/Testimonials/Index', ['testimonials' => $testimonials]);
    }

    public function create()
    {
        // Authorization would go here
        return inertia('@admin/Testimonials/Create');
    }

    public function store(Request $request)
    {
        $validatedData = $request->validate([
            'UserName'        => 'required|string|max:255',
            'UserEmail'       => 'required|email|max:255',
            'Message'         => 'required|string',
            'Rating'          => 'nullable|integer|min:1|max:5',
            'Attachments'     => 'nullable|image|max:2048', 
            'Status'          => 'required|in:Pending,Approved,Declined',
            'AdminComment'    => 'nullable|string',
            // 'SubmissionDate' will be set automatically below.
            // 'UserIP' is no longer validated as an input from the user.
            'DisplayOrder'    => 'nullable|integer',
        ]);
       
        $validatedData['SubmissionDate'] = now()->toDateTimeString();
        $validatedData['UserIP'] = $request->ip();
    
        // Convert Attachments to JSON if necessary.
        if ($request->hasFile('Attachments')) {
            $validatedData['Attachments'] = $request->file('Attachments')->store('testimonials', 'public');
        }
        $validatedData['organization_id'] = $request->user()?->current_organization_id;

        Testimonial::create($validatedData);
    
        return redirect()->route('testimonials.index')
                         ->with('success', 'Testimonial created successfully!');
    }

    public function show($id)
    {
        // Authorization would go here
        $orgId = auth()->user()?->current_organization_id;
        $testimonial = Testimonial::when($orgId, fn($q) => $q->forOrganization($orgId))->findOrFail($id);
        return inertia('@admin/Testimonials/Show', ['testimonial' => $testimonial]);
    }

    public function edit($id)
    {
        // Authorization would go here
        $orgId = auth()->user()?->current_organization_id;
        $testimonial = Testimonial::when($orgId, fn($q) => $q->forOrganization($orgId))->findOrFail($id);
        return inertia('@admin/Testimonials/Edit', ['testimonial' => $testimonial]);
    }

    public function update(Request $request, $id)
    {
        $orgId = auth()->user()?->current_organization_id;
        $testimonial = Testimonial::when($orgId, fn($q) => $q->forOrganization($orgId))->findOrFail($id);
    
        // Base rules for fields that are always validated.
        $rules = [
            'UserName'        => 'required|string|max:255',
            'UserEmail'       => 'required|email|max:255',
            'Message'         => 'required|string',
            'Rating'          => 'nullable|integer|min:1|max:5',
            'Status'          => 'required|in:Pending,Approved,Declined',
            'AdminComment'    => 'nullable|string',
            'SubmissionDate'  => 'required|date',
            'UserIP'          => 'required|string|max:45',
            'DisplayOrder'    => 'nullable|integer',
        ];
    
        // Add the Attachments rule only if a new file is uploaded.
        if ($request->hasFile('Attachments')) {
            $rules['Attachments'] = 'nullable|image|max:2048';
        }
    
        $validatedData = $request->validate($rules);
    
        // If a new file has been uploaded, store it and update the field.
        if ($request->hasFile('Attachments')) {
            $validatedData['Attachments'] = $request->file('Attachments')->store('testimonials', 'public');
        }
        // If no new file is uploaded, do not change the Attachments field
        // (it will remain the old file path)
    
        $testimonial->update($validatedData);
    
        return redirect()->route('testimonials.show', $testimonial->TestimonialID)
                         ->with('success', 'Testimonial updated successfully!');
    }

    public function destroy($id)
    {
        // Authorization would go here
        $orgId = auth()->user()?->current_organization_id;
        $testimonial = Testimonial::when($orgId, fn($q) => $q->forOrganization($orgId))->findOrFail($id);
        $testimonial->delete();
        return redirect()->route('testimonials.index')->with('success', 'Testimonial deleted successfully!');
    }
}
