<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ChatSession extends Model
{
    protected $fillable = ['child_id','section','messages'];
    protected $casts = ['messages' => 'array'];

    public function child()
    {
        return $this->belongsTo(Child::class);
    }
}
