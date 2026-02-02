<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ChildEnrollment extends Model
{
    protected $table = 'child_enrollments';

    protected $fillable = [
        'child_id',
        'course_id',
        'start_date',
        'status',
    ];

    public function child()
    {
        return $this->belongsTo(Child::class);
    }
        // Uncomment the following if you have a Course model:
    // public function course()
    // {
    //     return $this->belongsTo(Course::class);
    // }
}
