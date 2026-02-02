<?php
// app/Actions/SyncLessonService.php
namespace App\Actions;

use App\Models\Lesson;
use Illuminate\Support\Facades\DB;

class SyncLessonService
{
    /**
     * Update `lessons.service_id` for the given lesson IDs.
     */
    public function __invoke(int $serviceId, array $lessonIds): void
    {
        // 1. clear the column on lessons that are *leaving* this service
        Lesson::where('service_id', $serviceId)
              ->whereNotIn('id', $lessonIds)
              ->update(['service_id' => null]);

        // 2. set the column on the (new) linked lessons
        Lesson::whereIn('id', $lessonIds)
              ->update(['service_id' => $serviceId]);
    }
}
