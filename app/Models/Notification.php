<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Notification extends Model
{
    protected $table = 'notifications';

    protected $fillable = [
        'child_id',
        'message',
        'type',
        'read_status',
    ];

    public function child()
    {
        return $this->belongsTo(Child::class);
    }
    public function parent()
    {
        // child() returns a Child model, and Child has a `user()` relationship.
        return $this->hasOneThrough(
            User::class,
            Child::class,
            'id',        // Foreign key on Child’s table… (Child.id)
            'id',        // Foreign key on User’s table… (User.id)
            'child_id',  // Local key on Notification (notifications.child_id)
            'user_id'    // Local key on Child (children.user_id)
        );
    }
}
