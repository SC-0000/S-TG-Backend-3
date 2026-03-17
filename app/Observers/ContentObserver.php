<?php

namespace App\Observers;

use App\Events\ContentUpdated;
use Illuminate\Database\Eloquent\Model;

class ContentObserver
{
    public function created(Model $model): void
    {
        $this->fireEvent($model, 'created');
    }

    public function updated(Model $model): void
    {
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
