<?php

namespace App\Http\Controllers\Api;

use App\Http\Resources\ChangelogEntryResource;
use App\Models\ChangelogEntry;
use App\Models\ChangelogRead;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ChangelogController extends ApiController
{
    public function index(Request $request): JsonResponse
    {
        $portal = $request->query('portal', 'admin');
        $user = $request->user();

        $entries = ChangelogEntry::published()
            ->forPortal($portal)
            ->orderByDesc('published_at')
            ->limit(50)
            ->get();

        $readIds = ChangelogRead::where('user_id', $user->id)
            ->whereIn('changelog_entry_id', $entries->pluck('id'))
            ->pluck('changelog_entry_id')
            ->toArray();

        $data = $entries->map(function ($entry) use ($readIds) {
            $entry->is_read = in_array($entry->id, $readIds);
            return (new ChangelogEntryResource($entry))->resolve();
        })->all();

        return $this->success($data);
    }

    public function unreadCount(Request $request): JsonResponse
    {
        $portal = $request->query('portal', 'admin');
        $user = $request->user();

        $totalPublished = ChangelogEntry::published()
            ->forPortal($portal)
            ->count();

        $readCount = ChangelogRead::where('user_id', $user->id)
            ->whereHas('changelogEntry', function ($q) use ($portal) {
                $q->published()->forPortal($portal);
            })
            ->count();

        return $this->success(['unread_count' => $totalPublished - $readCount]);
    }

    public function markRead(Request $request, string $id): JsonResponse
    {
        $entry = ChangelogEntry::published()->findOrFail($id);
        $user = $request->user();

        ChangelogRead::firstOrCreate(
            [
                'user_id'            => $user->id,
                'changelog_entry_id' => $entry->id,
            ],
            [
                'read_at' => now(),
            ]
        );

        return $this->success(['message' => 'Marked as read.']);
    }
}
