<?php

namespace App\Events;

use App\Models\AssessmentSubmission;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class AssessmentSubmitted
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public AssessmentSubmission $submission,
        public int $organizationId
    ) {}
}
