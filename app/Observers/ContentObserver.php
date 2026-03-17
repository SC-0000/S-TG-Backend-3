<?php

namespace App\Observers;

use App\Events\ContentUpdated;
use Illuminate\Database\Eloquent\Model;

class ContentObserver
{
    /**
     * When true, model updates will NOT fire ContentUpdated events.
     * Used by background agents to prevent their auto-fixes from
     * re-triggering event-driven agent runs (infinite loop).
     */
    public static bool $suppressEvents = false;

    public function created(Model $model): void
    {
        if (static::$suppressEvents) return;
        $this->fireEvent($model, 'created');
    }

    public function updated(Model $model): void
    {
        if (static::$suppressEvents) return;

        // Only fire if significant fields changed (skip trivial updates like timestamps)
        $significant = array_diff(
            array_keys($model->getDirty()),
            ['updated_at', 'created_at']
        );

        if (!empty($significant)) {
            $this->fireEvent($model, 'updated');
        }
    }

    protected function fireEvent(Model $model, string $action): void
    {
        $organizationId = $model->organization_id ?? null;

        if (!$organizationId) {
            return;
        }

        ContentUpdated::dispatch($model, $organizationId, $action);
    }
}
