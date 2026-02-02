<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Application extends Model
{
    // Set the UUID as the primary key
    public $incrementing = false;
    protected $primaryKey = 'application_id';
    protected $keyType = 'string';

    protected $table = 'applications';

    protected $fillable = [
        'application_id',
        'applicant_name',
        'email',
        'phone_number',
        'application_status',
        'submitted_date',
        'application_type',
        'signature_path',
        'admin_feedback',
        'reviewer_id',
        'verification_token',
        'verified_at',
        'children_data',
        'referral_source',
        'address_line1',
        'address_line2',
        'mobile_number',
        'user_id',
        'organization_id',
    ];

    protected $casts = [
        'submitted_date' => 'datetime',
        'verified_at'    => 'datetime',
    ];
    public function children()
    {
        // One-to-many relationship: An application can have many children.
        return $this->hasMany(Child::class, 'application_id', 'application_id');
    }
     public function user()        // many-to-one
    {
        return $this->belongsTo(User::class);
    }
}
