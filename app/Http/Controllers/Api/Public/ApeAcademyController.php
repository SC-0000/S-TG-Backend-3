<?php

namespace App\Http\Controllers\Api\Public;

use App\Http\Controllers\Api\ApiController;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ApeAcademyController extends ApiController
{
    public function index(Request $request): JsonResponse
    {
        return $this->success([
            'pages' => [
                'home' => ['title' => 'Ape Academy'],
                'about' => ['title' => 'About Ape Academy'],
                'admissions' => ['title' => 'Admissions'],
            ],
        ]);
    }
}
