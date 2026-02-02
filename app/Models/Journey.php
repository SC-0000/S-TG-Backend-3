<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Journey extends Model
{
    protected $fillable = ['organization_id','title','description','exam_end_date'];
     public function categories() {
      return $this->hasMany(JourneyCategory::class);
     }

     public function organization(): BelongsTo
     {
         return $this->belongsTo(Organization::class);
     }

     public function scopeForOrganization($query, $organizationId)
     {
         return $query->where('organization_id', $organizationId);
     }
}
