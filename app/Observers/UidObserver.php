<?php 
// app/Observers/UidObserver.php
namespace App\Observers;

use App\Models\Assessment;
use App\Models\Lesson;
use Illuminate\Support\Str;

class UidObserver
{
    public function creating($model)
    {
        if (empty($model->uid)) {
            $model->uid = (string) Str::uuid();
        }
    }

    public function updating($model)
    {
        $model->sequence++;
    }
}

