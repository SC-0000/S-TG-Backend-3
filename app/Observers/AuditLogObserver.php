<?php

namespace App\Observers;

use App\Services\AuditLogger;
use Illuminate\Database\Eloquent\Model;

/**
 * Generic observer that writes one audit-log row per significant lifecycle event.
 * Register this observer for every model that should be audited.
 */
class AuditLogObserver
{
    public function created(Model $model): void
    {
        AuditLogger::log('created', $model, null);
    }

    public function updated(Model $model): void
    {
        $diff = AuditLogger::buildDiff($model);
        if ($diff === null) {
            return; // nothing meaningful changed
        }
        AuditLogger::log('updated', $model, $diff);
    }

    public function deleted(Model $model): void
    {
        AuditLogger::log('deleted', $model, null);
    }
}
