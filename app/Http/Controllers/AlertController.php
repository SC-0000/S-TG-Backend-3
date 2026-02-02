<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Alert;
use Inertia\Inertia;

class AlertController extends Controller
{
    public function create()
    {
        // Render the Inertia component for creating an alert.
        return Inertia::render('@admin/Alerts/CreateAlert');
    }

    public function store(Request $request)
    {
        // Validate the incoming request data.
        $validatedData = $request->validate([
            'title'              => 'required|string|max:255',
            'message'            => 'required|string',
            'type'               => 'required|in:info,warning,success,error',
            'priority'           => 'required|integer',
            'start_time'         => 'required|date',
            'end_time'           => 'nullable|date',
            'pages'              => 'nullable',
            'additional_context' => 'nullable|string|max:64',
        ]);

        // Convert pages (if provided) into an array.
        // if (!empty($validatedData['pages'])) {
        //     $decoded = array_map('trim', explode(',', $validatedData['pages']));
        //     $validatedData['pages'] = $decoded;
        // } else {
        //     $validatedData['pages'] = null;
        // }

        // Set created_by from the currently authenticated user.
        // Adjust this as needed for your authentication.
        $validatedData['created_by'] =  auth()->id() ?? 1;
        $validatedData['organization_id'] = $request->user()?->current_organization_id;

        // Create the alert.
        Alert::create($validatedData);

        return redirect()->route('alerts.index')
                         ->with('success', 'Alert created successfully!');
    }
    // List all alerts.
public function index()
{
    $orgId = auth()->user()?->current_organization_id;
    $alerts = Alert::when($orgId, fn($q) => $q->forOrganization($orgId))->get();
    return Inertia::render('@admin/Alerts/IndexAlert', ['alerts' => $alerts]);
}

// Display a single alert.
public function show($id)
{
    $orgId = auth()->user()?->current_organization_id;
    $alert = Alert::when($orgId, fn($q) => $q->forOrganization($orgId))->findOrFail($id);
    return Inertia::render('@admin/Alerts/ShowAlert', ['alert' => $alert]);
}

// Show the edit form for an alert.
public function edit($id)
{
    $orgId = auth()->user()?->current_organization_id;
    $alert = Alert::when($orgId, fn($q) => $q->forOrganization($orgId))->findOrFail($id);
    return Inertia::render('@admin/Alerts/EditAlert', ['alert' => $alert]);
}

// Update an alert.
public function update(Request $request, $id)
{
    $orgId = auth()->user()?->current_organization_id;
    $alert = Alert::when($orgId, fn($q) => $q->forOrganization($orgId))->findOrFail($id);

    $validatedData = $request->validate([
        'title'              => 'required|string|max:255',
        'message'            => 'required|string',
        'type'               => 'required|in:info,warning,success,error',
        'priority'           => 'required|integer',
        'start_time'         => 'required|date',
        'end_time'           => 'nullable|date',
        'pages'              => 'nullable', // Process as needed.
        'additional_context' => 'nullable|string|max:64',
    ]);

    // Convert pages to an array if needed (or leave it as is)
    if (!empty($validatedData['pages'])) {
        $validatedData['pages'] = array_map('trim', explode(',', $validatedData['pages']));
    } else {
        $validatedData['pages'] = null;
    }

    $alert->update($validatedData);

    return redirect()->route('alerts.show', $alert->alert_id)
                     ->with('success', 'Alert updated successfully!');
}

// Delete an alert.
public function destroy($id)
{
    $orgId = auth()->user()?->current_organization_id;
    $alert = Alert::when($orgId, fn($q) => $q->forOrganization($orgId))->findOrFail($id);
    $alert->delete();

    return redirect()->route('alerts.index')
                     ->with('success', 'Alert deleted successfully!');
}

}
