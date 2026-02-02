<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class JourneyCategory extends Model
{
    protected $fillable = ['organization_id','journey_id','topic','name','description'];
  public function journey() {
    return $this->belongsTo(Journey::class);
  }
  public function lessons()
{
    return $this->hasMany(Lesson::class, 'journey_category_id');
}

public function assessments()
{
    return $this->hasMany(Assessment::class,   'journey_category_id');
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
