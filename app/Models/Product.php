<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Product extends Model
{
    protected $fillable = [
        'organization_id',
        'name',
        'description',
        'price',
        'stock_status',
        'category',
        'image_path',
        'related_lesson_id',
        'discount',
    ];
    protected $appends = ['display_name'];
public function getDisplayNameAttribute()
{
    return $this->name;
}

    public function lesson()
    {
        return $this->belongsTo(LiveLessonSession::class, 'related_lesson_id');
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function discounts()
    {
        return $this->hasMany(ProductDiscount::class);
    }

    public function orderItems()
    {
        return $this->hasMany(ProductOrderItem::class);
    }

    public function scopeForOrganization($query, $organizationId)
    {
        return $query->where('organization_id', $organizationId);
    }
}
