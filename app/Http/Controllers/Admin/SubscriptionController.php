<?php

namespace App\Http\Controllers\Admin;

use App\Models\Subscription;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Inertia\Inertia;

class SubscriptionController extends Controller
{
    /* GET /@admin/subscriptions */
    public function index()
    {
        return Inertia::render('@admin/Subscriptions/Index', [
            'plans' => Subscription::select('id', 'name', 'slug')
                        ->withCount('users')        // adds users_count
                        ->latest('id')
                        ->paginate(15),
        ]);
    }

    /* GET /@admin/subscriptions/create */
    public function create()
    {
        return Inertia::render('@admin/Subscriptions/Create');
    }

    /* POST /@admin/subscriptions */
    public function store(Request $request)
    {
        $data = $request->validate([
            'name'     => 'required|string|max:255',
            'slug'     => 'required|alpha_dash|max:255|unique:subscriptions,slug',
            'features' => 'required|array',
            'content_filters' => 'nullable|array',
        ]);

        Subscription::create($data);

        return redirect()->route('subscriptions.index')
                         ->with('success', 'Plan created.');
    }

    /* GET /@admin/subscriptions/{subscription}/edit */
    public function edit(Subscription $subscription)
    {
        return Inertia::render('@admin/Subscriptions/Edit', [
            'plan' => $subscription,
        ]);
    }

    /* PUT /@admin/subscriptions/{subscription} */
    public function update(Request $request, Subscription $subscription)
    {
        $data = $request->validate([
            'name'     => 'required|string|max:255',
            'slug'     => 'required|alpha_dash|max:255|unique:subscriptions,slug,'.$subscription->id,
            'features' => 'required|array',
            'content_filters' => 'nullable|array',
        ]);

        $subscription->update($data);

        return redirect()->route('subscriptions.index')
                         ->with('success', 'Plan updated.');
    }

    /* DELETE /@admin/subscriptions/{subscription} */
    public function destroy(Subscription $subscription)
    {
        $subscription->delete();

        return redirect()->route('subscriptions.index')
                         ->with('success', 'Plan deleted.');
    }
}
