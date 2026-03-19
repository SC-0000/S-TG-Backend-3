<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Api\ApiController;
use App\Models\NewsletterSubscriber;
use App\Support\ApiPagination;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class EmailSubscriberController extends ApiController
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! $user) {
            return $this->error('Unauthenticated.', [], 401);
        }

        $orgId = $request->attributes->get('organization_id') ?: $user->current_organization_id;

        $query = NewsletterSubscriber::query()
            ->when($orgId, fn ($q) => $q->where('organization_id', $orgId))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->when($request->filled('search'), function ($q) use ($request) {
                $search = $request->string('search');
                $q->where(function ($inner) use ($search) {
                    $inner->where('email', 'like', "%{$search}%")
                        ->orWhere('name', 'like', "%{$search}%");
                });
            })
            ->latest();

        $subscribers = $query->paginate(ApiPagination::perPage($request, 20));

        $data = $subscribers->getCollection()->map(function (NewsletterSubscriber $subscriber) {
            return [
                'id' => $subscriber->id,
                'email' => $subscriber->email,
                'name' => $subscriber->name,
                'status' => $subscriber->status,
                'source' => $subscriber->source,
                'subscribed_at' => $subscriber->subscribed_at,
                'unsubscribed_at' => $subscriber->unsubscribed_at,
                'created_at' => $subscriber->created_at,
            ];
        })->all();

        return $this->paginated($subscribers, $data);
    }

    public function store(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! $user) {
            return $this->error('Unauthenticated.', [], 401);
        }

        $payload = $request->validate([
            'email' => ['required', 'email', 'max:255'],
            'name' => ['nullable', 'string', 'max:255'],
        ]);

        $orgId = $request->attributes->get('organization_id') ?: $user->current_organization_id;
        if (! $orgId) {
            return $this->error('Organization is required.', [], 422);
        }

        $subscriber = NewsletterSubscriber::firstOrNew([
            'organization_id' => $orgId,
            'email' => $payload['email'],
        ]);

        if (! $subscriber->unsubscribe_token) {
            $subscriber->unsubscribe_token = Str::random(40);
        }

        $subscriber->fill([
            'name' => $payload['name'] ?? $subscriber->name,
            'status' => 'active',
            'source' => $subscriber->source ?? 'manual',
            'subscribed_at' => now(),
            'unsubscribed_at' => null,
        ]);

        $subscriber->save();

        return $this->success([
            'id' => $subscriber->id,
            'email' => $subscriber->email,
            'name' => $subscriber->name,
            'status' => $subscriber->status,
            'source' => $subscriber->source,
            'subscribed_at' => $subscriber->subscribed_at,
        ], [], 201);
    }

    public function unsubscribe(Request $request, NewsletterSubscriber $subscriber): JsonResponse
    {
        $user = $request->user();
        if (! $user) {
            return $this->error('Unauthenticated.', [], 401);
        }

        $orgId = $request->attributes->get('organization_id') ?: $user->current_organization_id;
        if ($orgId && (int) $subscriber->organization_id !== (int) $orgId) {
            return $this->error('Unauthorized access.', [], 403);
        }

        $subscriber->status = 'unsubscribed';
        $subscriber->unsubscribed_at = now();
        $subscriber->save();

        return $this->success([
            'id' => $subscriber->id,
            'email' => $subscriber->email,
            'name' => $subscriber->name,
            'status' => $subscriber->status,
            'source' => $subscriber->source,
            'subscribed_at' => $subscriber->subscribed_at,
            'unsubscribed_at' => $subscriber->unsubscribed_at,
        ]);
    }

    public function resubscribe(Request $request, NewsletterSubscriber $subscriber): JsonResponse
    {
        $user = $request->user();
        if (! $user) {
            return $this->error('Unauthenticated.', [], 401);
        }

        $orgId = $request->attributes->get('organization_id') ?: $user->current_organization_id;
        if ($orgId && (int) $subscriber->organization_id !== (int) $orgId) {
            return $this->error('Unauthorized access.', [], 403);
        }

        $subscriber->status = 'active';
        $subscriber->subscribed_at = now();
        $subscriber->unsubscribed_at = null;
        $subscriber->save();

        return $this->success([
            'id' => $subscriber->id,
            'email' => $subscriber->email,
            'name' => $subscriber->name,
            'status' => $subscriber->status,
            'source' => $subscriber->source,
            'subscribed_at' => $subscriber->subscribed_at,
            'unsubscribed_at' => $subscriber->unsubscribed_at,
        ]);
    }
}
