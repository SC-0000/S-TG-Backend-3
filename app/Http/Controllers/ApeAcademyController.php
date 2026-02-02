<?php

namespace App\Http\Controllers;

use Inertia\Inertia;

class ApeAcademyController extends Controller
{
    public function index()
    {
        return Inertia::render('@public/ApeAcademy/Home');
    }

    public function about()
    {
        return Inertia::render('@public/ApeAcademy/About');
    }

    public function admissions()
    {
        return Inertia::render('@public/ApeAcademy/Admissions');
    }
}
