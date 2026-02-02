<?php

namespace App\Http\Controllers\Api\Content;

use App\Http\Controllers\Api\ApiController;
use App\Models\Course;
use App\Support\ApiPagination;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TeacherCourseController extends ApiController
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        $courses = Course::query()
            ->forTeacher($user->id)
            ->paginate(ApiPagination::perPage($request));

        $data = $courses->getCollection()->map(fn ($course) => [
            'id' => $course->id,
            'uid' => $course->uid,
            'title' => $course->title,
            'description' => $course->description,
            'thumbnail' => $course->thumbnail,
            'year_group' => $course->year_group,
            'category' => $course->category,
            'status' => $course->status,
        ])->all();

        return $this->paginated($courses, $data);
    }
}
