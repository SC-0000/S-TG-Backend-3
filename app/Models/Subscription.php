<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Subscription extends Model
{
    protected $fillable = ['name', 'slug', 'features', 'content_filters'];   // add 'is_active' if you created that column
    protected $casts    = ['features' => 'array', 'content_filters' => 'array'];


    public function users()
    {
        return $this->belongsToMany(User::class, 'user_subscriptions')
                    ->withPivot(['starts_at','ends_at','status'])
                    ->wherePivot('status','active');
            
    }
}
