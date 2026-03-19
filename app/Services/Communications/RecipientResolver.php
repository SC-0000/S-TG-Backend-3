<?php

namespace App\Services\Communications;

use App\Models\Child;
use App\Models\NewsletterSubscriber;
use App\Models\User;
use Illuminate\Support\Collection;

class RecipientResolver
{
    public function __construct(
        protected ?int $organizationId,
        protected array $filters = []
    ) {}

    public function resolve(): array
    {
        $roles = array_values(array_filter((array) ($this->filters['roles'] ?? [])));
        $includeUsers = (bool) ($this->filters['include_users'] ?? true);
        $includeSubscribers = (bool) ($this->filters['include_subscribers'] ?? true);
        $userIds = array_values(array_filter((array) ($this->filters['user_ids'] ?? [])));
        $subscriberIds = array_values(array_filter((array) ($this->filters['subscriber_ids'] ?? [])));
        $search = trim((string) ($this->filters['search'] ?? ''));

        $users = collect();
        if ($includeUsers || count($userIds) > 0) {
            $userQuery = User::query()
                ->whereNotNull('email')
                ->when($this->organizationId, fn ($q) => $q->where('current_organization_id', $this->organizationId));

            if (count($roles) > 0) {
                $userQuery->whereIn('role', $roles);
            }
            if (count($userIds) > 0) {
                $userQuery->whereIn('id', $userIds);
            }
            if ($search !== '') {
                $userQuery->where(function ($q) use ($search) {
                    $q->where('name', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%");
                });
            }

            $users = $userQuery->get(['id', 'name', 'email', 'role']);
        }

        $subscribers = collect();
        if ($includeSubscribers || count($subscriberIds) > 0) {
            $subQuery = NewsletterSubscriber::query()
                ->where('status', 'active')
                ->whereNotNull('email')
                ->when($this->organizationId, fn ($q) => $q->where('organization_id', $this->organizationId));

            if (count($subscriberIds) > 0) {
                $subQuery->whereIn('id', $subscriberIds);
            }
            if ($search !== '') {
                $subQuery->where(function ($q) use ($search) {
                    $q->where('name', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%");
                });
            }

            $subscribers = $subQuery->get(['id', 'name', 'email', 'status', 'organization_id']);
        }

        $notificationParents = $this->resolveNotificationParents($roles, $userIds, $search);

        return [
            'users' => $users,
            'subscribers' => $subscribers,
            'notification_parents' => $notificationParents,
        ];
    }

    protected function resolveNotificationParents(array $roles, array $userIds, string $search): Collection
    {
        $parentId = $this->filters['parent_id'] ?? null;
        $childId = $this->filters['child_id'] ?? null;

        if (count($roles) > 0 && !in_array('parent', $roles, true)) {
            return collect();
        }

        if ($childId) {
            $child = Child::query()
                ->when($this->organizationId, fn ($q) => $q->where('organization_id', $this->organizationId))
                ->find($childId);

            if (!$child || !$child->user) {
                return collect();
            }

            return collect([$child->user->load('children:id,child_name,user_id')]);
        }

        $parentQuery = User::query()
            ->where('role', 'parent')
            ->when($this->organizationId, fn ($q) => $q->where('current_organization_id', $this->organizationId));

        if ($parentId) {
            $parentQuery->where('id', $parentId);
        }

        if (count($userIds) > 0) {
            $parentQuery->whereIn('id', $userIds);
        }

        if ($search !== '') {
            $parentQuery->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%");
            });
        }

        return $parentQuery->with('children:id,child_name,user_id')->get();
    }
}
