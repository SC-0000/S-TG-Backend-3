<?php

namespace App\Http\Controllers\Admin;

use App\Models\User;
use App\Models\Subscription;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class UserSubscriptionController extends Controller
{
    /* ────────────────────────────
       OVERVIEW  /@admin/user-subscriptions
    ──────────────────────────── */
    public function index(Request $request)
    {
        $pivotRows = DB::table('user_subscriptions as us')
            ->join('users',        'users.id',        '=', 'us.user_id')
            ->join('subscriptions','subscriptions.id','=','us.subscription_id')
            ->select(
                'us.id',
                'users.id as user_id',
                'users.name as user_name',
                'users.email',
                'subscriptions.name as plan_name',
                'subscriptions.slug',
                'us.status',
                'us.starts_at',
                'us.ends_at'
            )
            ->when($request->plan, fn ($q,$v) => $q->where('subscriptions.slug',$v))
            ->latest('us.id')
            ->paginate(25)
            ->withQueryString();

        return Inertia::render('@admin/UserSubscriptions/Index', [
            'rows'   => $pivotRows,
            'plans'  => Subscription::select('slug','name')->orderBy('name')->get(),
            'filter' => $request->only('plan'),
        ]);
    }
        /* ────────────────────────────
       GRANT to ANY user (new page)
    ──────────────────────────── */
    public function grant()
    {
        // Exclude users who already have all plans? For now, show all users.
        $users = User::select('id', 'name', 'email')->orderBy('name')->get();
        $plans = Subscription::select('id', 'name', 'slug')->orderBy('name')->get();

        return Inertia::render('@admin/UserSubscriptions/GrantSubscription', [
            'users' => $users,
            'plans' => $plans,
        ]);
    }

    /* POST /user-subscriptions/grant (grant to any user) */
    public function storeForNewUser(Request $request)
    {
        $data = $request->validate([
            'user_id'         => 'required|exists:users,id',
            'subscription_id' => 'required|exists:subscriptions,id',
            'days'            => 'nullable|integer|min:0',
        ]);

        $user = User::findOrFail($data['user_id']);
        $days = (int) ($data['days'] ?? 0);

        // Prevent duplicate subscription for same plan
        if ($user->subscriptions()->where('subscriptions.id', $data['subscription_id'])->exists()) {
            return back()->withErrors(['user_id' => 'User already has this subscription plan.']);
        }

        $user->subscriptions()->attach($data['subscription_id'], [
            'starts_at' => now(),
            'ends_at'   => $days > 0 ? now()->addDays($days) : null,
            'status'    => 'active',
            'source'    => 'manual',
        ]);

        return back()->with('success', 'Plan granted.');
    }

    /* ────────────────────────────
       SHOW / GRANT for ONE user
    ──────────────────────────── */
    public function show(User $user)
    {
        return Inertia::render('@admin/UserSubscriptions/Show', [
            'user'  => $user->load([
                'subscriptions' => fn ($q) => $q->withPivot(['id','starts_at','ends_at','status']),
            ]),
            'plans' => Subscription::select('id','name','slug')->get(),
        ]);
    }

    /* POST /users/{user}/subscriptions          (grant) */
    public function store(Request $request, User $user)
    {
        $data = $request->validate([
            'subscription_id' => 'required|exists:subscriptions,id',
            'days'            => 'nullable|integer|min:0',   // 0 = no expiry
        ]);

        $days = (int) ($data['days'] ?? 0);       // ← CAST RIGHT HERE

        $user->subscriptions()->attach($data['subscription_id'], [
            'starts_at' => now(),
            'ends_at'   => $days > 0 ? now()->addDays($days) : null,
            'status'    => 'active',
            'source'    => 'manual',
        ]);

        return back()->with('success', 'Plan granted.');
    }

    /* DELETE /users/{user}/subscriptions/{pivot}   (revoke) */
    public function destroy(User $user, $pivot)
    {
        $user->subscriptions()->wherePivot('id', $pivot)->detach();
        return back()->with('success', 'Plan revoked.');
    }
}