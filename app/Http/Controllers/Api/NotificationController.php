<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\ApiController;
use App\Models\AppNotification;
use App\Support\ApiPagination;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class NotificationController extends ApiController
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        if (!$user) {
            return $this->error('Unauthenticated.', [], 401);
        }

        $notifications = AppNotification::where('user_id', $user->id)
            ->orderByDesc('created_at')
            ->paginate(ApiPagination::perPage($request));

        $data = collect($notifications->items())->map(function ($n) {
            return [
                'id' => $n->id,
                'title' => $n->title,
                'message' => $n->message,
                'type' => $n->type,
                'status' => $n->status,
                'channel' => $n->channel,
                'created_at' => $n->created_at?->toISOString(),
            ];
        })->all();

        return $this->paginated($notifications, $data);
    }

    public function unread(Request $request): JsonResponse
    {
        $user = $request->user();
        if (!$user) {
            return $this->error('Unauthenticated.', [], 401);
        }

        $unreadCount = AppNotification::where('user_id', $user->id)
            ->where('status', 'unread')
            ->count();

        $notifications = AppNotification::where('user_id', $user->id)
            ->where('status', 'unread')
            ->orderByDesc('created_at')
            ->limit(3)
            ->get(['id', 'title', 'message', 'created_at'])
            ->map(fn ($n) => [
                'id' => $n->id,
                'title' => $n->title,
                'message' => $n->message,
                'created_at' => $n->created_at?->toISOString(),
            ]);

        return $this->success([
            'unread_count' => $unreadCount,
            'notifications' => $notifications,
        ]);
    }

    public function markRead(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        if (!$user) {
            return $this->error('Unauthenticated.', [], 401);
        }

        $notification = AppNotification::where('user_id', $user->id)->findOrFail($id);
        $notification->status = 'read';
        $notification->save();

        return $this->success(['message' => 'Notification marked as read.']);
    }

    public function markAllRead(Request $request): JsonResponse
    {
        $user = $request->user();
        if (!$user) {
            return $this->error('Unauthenticated.', [], 401);
        }

        AppNotification::where('user_id', $user->id)
            ->where('status', 'unread')
            ->update(['status' => 'read']);

        return $this->success(['message' => 'All notifications marked as read.']);
    }
}
