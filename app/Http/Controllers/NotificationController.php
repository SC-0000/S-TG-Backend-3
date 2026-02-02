<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\AppNotification;
use App\Models\Child;
use App\Models\Notification;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;

class NotificationController extends Controller
{
    public function unread()
{
    $userId = auth()->id();

    // count all unread
    $unreadCount = AppNotification::where('status', 'unread')
                        ->where('user_id',$userId)
                        ->count();

    // top 3 most recent unread
    $raw = AppNotification::where('status', 'unread')
            ->where('user_id',$userId) 
            ->orderByDesc('created_at')
            ->limit(3)
            ->get(['id','title','message','created_at']);

    $notifications = $raw->map(fn($n) => [
        'id'      => $n->id,
        'title'   => $n->title,
        'message' => $n->message,
        // human-friendly timestamp
        'time'    => Carbon::parse($n->created_at)->diffForHumans(),
    ]);

    return response()->json([
        'unreadCount'   => $unreadCount,
        'notifications' => $notifications,
    ]);
}
    public function portalIndex(Request $request)
    {
        $userId = $request->user()->id;


        // unread count
        $unreadCount = AppNotification::where('user_id', $userId)->where('status', 'unread')
                           ->count();

        // latest 50 notifications
        $notifications = AppNotification::where('user_id', $userId)->orderByDesc('created_at')
            ->paginate(50)
            ->through(fn($n) => [
                'id'       => $n->id,
                'title'    => $n->title,
                'message'  => $n->message,
                'type'     => $n->type,
                'status'   => $n->status,
                'channel'  => $n->channel,
                'created'  => $n->created_at->diffForHumans(),
            ]);

        return Inertia::render('@parent/Notifications/Index', [
            'notifications' => $notifications,
            'unreadCount'   => $unreadCount,
        ]);
    }
      public function markRead($id)
    {
        $n = AppNotification::findOrFail($id);
        $n->status = 'read';
        $n->save();
        return response()->json(['success' => true]);
    }

    public function markAllRead()
    {
        AppNotification::where('status', 'unread')
            ->update(['status' => 'read']);

        return response()->json(['success' => true]);
    }
     public function index(Request $request)
    {
        // 1. Load all parents (with their children) for the filter dropdown
        $parents = User::where('role','parent')
                       ->select('id','name')
                       ->with('children:id,child_name,user_id')
                       ->orderBy('name')
                       ->get();

        // 2. Prepare the base query (eager-load the parent user)
        $query = AppNotification::with('user');

        // 3. Collect incoming filters
        $filters = $request->only(['parent_id','child_id']);

        // 4. If a specific parent is selected, filter by user_id
        $children = [];
        if ($request->filled('parent_id')) {
            $query->where('user_id', $request->parent_id);

            // also load that parent’s children for the child-dropdown
            $parent = collect($parents)->firstWhere('id', (int) $request->parent_id);
            $children = $parent ? $parent->children : [];
        }

        // 5. If a specific child is selected, filter by matching the child’s name in the message
        if ($request->filled('child_id')) {
            $child = Child::findOrFail($request->child_id);
            $query->where('message', 'like', "%For “{$child->child_name}”: %");
        }

        // 6. Paginate (and carry over the filter query-string)
        $notifications = $query
            ->orderByDesc('created_at')
            ->paginate(50)
            ->appends($filters);
// Log::info('Notifications:', $notifications);
        // 7. Render the Inertia page
        return Inertia::render('@admin/Notifications/Index', [
            'notifications' => $notifications,
            'parents'       => $parents,
            'children'      => $children,
            'filters'       => $filters,
        ]);
    }
   public function create()
    {
        // Load all parents + their children
        $parents = User::where('role','parent')
                       ->select('id','name')
                       ->with('children:id,child_name,user_id')
                       ->orderBy('name')
                       ->get();

        return Inertia::render('@admin/Notifications/Create', [
            'parents' => $parents,
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'parent_id' => 'nullable|exists:users,id',
            'child_id'  => 'nullable|exists:children,id',
            'title'     => 'required|string|max:255',
            'message'   => 'required|string',
            'type'      => 'required|in:lesson,assessment,payment,task',
            'status'    => 'required|in:unread,read',
            'channel'   => 'required|in:email,sms,in-app,push',
        ]);

        // Helper to create one notification for a given child+parent:
        $sendToChild = function($child, $parent, $text) {
            AppNotification::create([
                'user_id'  => $parent->id,
                'title'    => $text['title'],
                'message'  => "For “{$child->child_name}”: {$text['message']}",
                'type'     => $text['type'],
                'status'   => $text['status'],
                'channel'  => $text['channel'],
            ]);
        };

        // CASE A) No parent & no child => “everyone”
        if (empty($data['parent_id']) && empty($data['child_id'])) {
            User::where('role','parent')
                ->with('children:id,child_name,user_id')
                ->get()
                ->each(function($parent) use($data, $sendToChild) {
                    foreach ($parent->children as $child) {
                        $sendToChild($child, $parent, $data);
                    }
                });
        }
        // CASE B) Parent only => all that parent’s kids
        elseif ($data['parent_id'] && empty($data['child_id'])) {
            $parent = User::with('children:id,child_name,user_id')
                          ->findOrFail($data['parent_id']);
            foreach ($parent->children as $child) {
                $sendToChild($child, $parent, $data);
            }
        }
        // CASE C) Specific child => only that one
        else {
            $child  = \App\Models\Child::findOrFail($data['child_id']);
            $parent = $child->user;
            $sendToChild($child, $parent, $data);
        }

        return redirect()
            ->route('notifications.index')
            ->with('success','Notification(s) created');
    }

    public function show($id)
    {
        // 1. Load the notification + its parent user
        $notification = AppNotification::with('user')
            ->findOrFail($id);

        // 2. Shape the data you want to send to the client
        $data = [
            'id'         => $notification->id,
            'user'       => [
                'id'   => $notification->user?->id,
                'name' => $notification->user?->name,
            ],
           'child'   => Str::contains($notification->message, ':')
                          ? Str::before($notification->message, ':')
                          : null,
            'text'    => Str::contains($notification->message, ':')
                          ? trim(Str::after($notification->message, ':'))
                          : $notification->message,
            'message'    => $notification->message,
            'type'       => $notification->type,
            'status'     => $notification->status,
            'channel'    => $notification->channel,
            'created_at' => $notification->created_at->toDateTimeString(),
        ];

        return Inertia::render('@admin/Notifications/Show', [
            'notification' => $data,
        ]);
    }

    public function edit($id)
    {
        $notification = AppNotification::findOrFail($id);
        return Inertia::render('@admin/Notifications/Edit', ['notification' => $notification]);
    }

    public function update(Request $request, $id)
    {
        $notification = AppNotification::findOrFail($id);
        $validatedData = $request->validate([
            'title'   => 'required|string|max:255',
            'message' => 'required|string',
            'type'    => 'required|in:lesson,assessment,payment,task',
            'status'  => 'required|in:unread,read',
            'channel' => 'required|in:email,sms,in-app,push',
        ]);

        $notification->update($validatedData);

        return redirect()->route('notifications.show', $notification->id)
                         ->with('success', 'Notification updated successfully!');
    }

    public function destroy($id)
    {
        $notification = AppNotification::findOrFail($id);
        $notification->delete();
        return redirect()->route('notifications.index')->with('success', 'Notification deleted successfully!');
    }
}
