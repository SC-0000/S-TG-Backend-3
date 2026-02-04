<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Api\ApiController;
use App\Models\AppNotification;
use App\Models\Child;
use App\Models\User;
use App\Support\ApiPagination;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class NotificationController extends ApiController
{
    public function index(Request $request): JsonResponse
    {
        $orgId = $request->attributes->get('organization_id');

        $parentsQuery = User::where('role', 'parent')
            ->select('id', 'name')
            ->with('children:id,child_name,user_id');

        if ($orgId) {
            $parentsQuery->where('current_organization_id', $orgId);
        }

        $parents = $parentsQuery->orderBy('name')->get();

        $query = AppNotification::with('user');
        $filters = $request->only(['parent_id', 'child_id']);

        $children = [];
        if ($request->filled('parent_id')) {
            $query->where('user_id', $request->integer('parent_id'));
            $parent = $parents->firstWhere('id', (int) $request->parent_id);
            $children = $parent ? $parent->children : [];
        }

        if ($request->filled('child_id')) {
            $child = Child::findOrFail($request->integer('child_id'));
            $query->where('message', 'like', "%For “{$child->child_name}”: %");
        }

        if ($request->filled('status')) {
            $query->where('status', $request->string('status'));
        }

        if ($request->filled('type')) {
            $query->where('type', $request->string('type'));
        }

        if ($request->filled('search')) {
            $search = $request->string('search');
            $query->where(function ($q) use ($search) {
                $q->where('message', 'like', '%' . $search . '%')
                    ->orWhere('title', 'like', '%' . $search . '%');
            });
        }

        $notifications = $query
            ->orderByDesc('created_at')
            ->paginate(ApiPagination::perPage($request, 50));

        $data = collect($notifications->items())->map(function ($n) {
            return [
                'id' => $n->id,
                'title' => $n->title,
                'message' => $n->message,
                'type' => $n->type,
                'status' => $n->status,
                'channel' => $n->channel,
                'child_id' => $n->child_id ?? null,
                'user' => $n->user ? [
                    'id' => $n->user->id,
                    'name' => $n->user->name,
                    'email' => $n->user->email,
                ] : null,
                'created_at' => $n->created_at?->toISOString(),
            ];
        })->all();

        return $this->success([
            'notifications' => $data,
            'parents' => $parents,
            'children' => $children,
            'filters' => $filters,
            'pagination' => [
                'total' => $notifications->total(),
                'count' => $notifications->count(),
                'per_page' => $notifications->perPage(),
                'current_page' => $notifications->currentPage(),
                'total_pages' => $notifications->lastPage(),
            ],
        ]);
    }

    public function createData(Request $request): JsonResponse
    {
        $orgId = $request->attributes->get('organization_id');

        $parentsQuery = User::where('role', 'parent')
            ->select('id', 'name')
            ->with('children:id,child_name,user_id');

        if ($orgId) {
            $parentsQuery->where('current_organization_id', $orgId);
        }

        $parents = $parentsQuery->orderBy('name')->get();

        return $this->success(['parents' => $parents]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'parent_id' => 'nullable|exists:users,id',
            'child_id' => 'nullable|exists:children,id',
            'title' => 'required|string|max:255',
            'message' => 'required|string',
            'type' => 'required|in:lesson,assessment,payment,task',
            'status' => 'required|in:unread,read',
            'channel' => 'required|in:email,sms,in-app,push',
        ]);

        $sendToChild = function ($child, $parent, $text) {
            AppNotification::create([
                'user_id' => $parent->id,
                'child_id' => $child->id,
                'title' => $text['title'],
                'message' => "For “{$child->child_name}”: {$text['message']}",
                'type' => $text['type'],
                'status' => $text['status'],
                'channel' => $text['channel'],
            ]);
        };

        if (empty($data['parent_id']) && empty($data['child_id'])) {
            User::where('role', 'parent')
                ->with('children:id,child_name,user_id')
                ->get()
                ->each(function ($parent) use ($data, $sendToChild) {
                    foreach ($parent->children as $child) {
                        $sendToChild($child, $parent, $data);
                    }
                });
        } elseif (!empty($data['parent_id']) && empty($data['child_id'])) {
            $parent = User::with('children:id,child_name,user_id')
                ->findOrFail($data['parent_id']);
            foreach ($parent->children as $child) {
                $sendToChild($child, $parent, $data);
            }
        } else {
            $child = Child::findOrFail($data['child_id']);
            $parent = $child->user;
            $sendToChild($child, $parent, $data);
        }

        return $this->success(['message' => 'Notification(s) created'], [], 201);
    }

    public function show(Request $request, AppNotification $notification): JsonResponse
    {
        $notification->load('user');

        $data = [
            'id' => $notification->id,
            'user' => $notification->user ? [
                'id' => $notification->user->id,
                'name' => $notification->user->name,
            ] : null,
            'child' => Str::contains($notification->message, ':')
                ? Str::before($notification->message, ':')
                : null,
            'text' => Str::contains($notification->message, ':')
                ? trim(Str::after($notification->message, ':'))
                : $notification->message,
            'message' => $notification->message,
            'type' => $notification->type,
            'status' => $notification->status,
            'channel' => $notification->channel,
            'created_at' => $notification->created_at?->toDateTimeString(),
        ];

        return $this->success(['notification' => $data]);
    }

    public function update(Request $request, AppNotification $notification): JsonResponse
    {
        $validatedData = $request->validate([
            'title' => 'required|string|max:255',
            'message' => 'required|string',
            'type' => 'required|in:lesson,assessment,payment,task',
            'status' => 'required|in:unread,read',
            'channel' => 'required|in:email,sms,in-app,push',
        ]);

        $notification->update($validatedData);

        return $this->success(['message' => 'Notification updated successfully!']);
    }

    public function destroy(Request $request, AppNotification $notification): JsonResponse
    {
        $notification->delete();

        return $this->success(['message' => 'Notification deleted successfully!']);
    }
}
