<?php

namespace App\Events;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ContentUpdated
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public Model $content,
        public int $organizationId,
        public string $action = 'updated' // 'created' or 'updated'
    ) {}
}
