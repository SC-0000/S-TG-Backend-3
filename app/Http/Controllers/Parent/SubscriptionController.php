<?php

namespace App\Http\Controllers\Parent;

use App\Http\Controllers\Controller;
use App\Models\Subscription;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class SubscriptionController extends Controller
{
    public function assign(Subscription $subscription, Request $request)
    {
        $data = $request->validate([
            'child_id' => 'required|exists:children,id',
        ]);
        
        $user = Auth::user();
        
        // Verify child belongs to user
        if (!$user->children()->where('id', $data['child_id'])->exists()) {
            return back()->withErrors(['message' => 'Invalid child selection.']);
        }
        
        // Verify subscription belongs to user
        if (!$user->subscriptions()->where('subscriptions.id', $subscription->id)->exists()) {
            return back()->withErrors(['message' => 'Invalid subscription.']);
        }
        
        // Update pivot to assign child
        $user->subscriptions()->updateExistingPivot(
            $subscription->id,
            ['child_id' => $data['child_id']]
        );
        
        Log::info('Subscription assigned to child', [
            'user_id' => $user->id,
            'subscription_id' => $subscription->id,
            'child_id' => $data['child_id'],
        ]);
        
        // Grant access immediately
        $child = \App\Models\Child::find($data['child_id']);
        
        $yearGroupService = app(\App\Services\YearGroupSubscriptionService::class);
        $yearGroupService->grantAccess($user, $subscription, $child);
        
        return back();
    }

    public function assignChild(Request $request)
    {
        $data = $request->validate([
            'subscription_id' => 'required|exists:subscriptions,id',
            'child_id' => 'required|exists:children,id',
        ]);
        
        $user = Auth::user();
        
        // Verify child belongs to user
        if (!$user->children()->where('id', $data['child_id'])->exists()) {
            return back()->with('error', 'Invalid child selection.');
        }
        
        // Update pivot
        $user->subscriptions()->updateExistingPivot(
            $data['subscription_id'],
            ['child_id' => $data['child_id']]
        );
        
        Log::info('Subscription assigned to child', [
            'user_id' => $user->id,
            'subscription_id' => $data['subscription_id'],
            'child_id' => $data['child_id'],
        ]);
        
        // Grant access immediately
        $subscription = \App\Models\Subscription::find($data['subscription_id']);
        $child = \App\Models\Child::find($data['child_id']);
        
        $yearGroupService = app(\App\Services\YearGroupSubscriptionService::class);
        $yearGroupService->grantAccess($user, $subscription, $child);
        
        return redirect()->back()->with('success', 'Subscription assigned successfully!');
    }
}
