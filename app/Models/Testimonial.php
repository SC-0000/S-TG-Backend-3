<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Testimonial extends Model
{
    // The table name is "testimonials".
    protected $table = 'testimonials';

    // The primary key is TestimonialID.
    protected $primaryKey = 'TestimonialID';

    // Allow mass assignment on these fields.
    protected $fillable = [
        'organization_id',
        'UserName',
        'UserEmail',
        'Message',
        'Rating',
        'Attachments',
        'Status',
        'AdminComment',
        'SubmissionDate',
        'UserIP',
        'DisplayOrder',
    ];

    // Cast the Attachments column to array (if stored as JSON)
    protected $casts = [
        
        'SubmissionDate' => 'datetime',
    ];

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function scopeForOrganization($query, $organizationId)
    {
        return $query->where('organization_id', $organizationId);
    }
}
