<?php

namespace App\Http\Controllers;

use App\Models\Article;
use App\Models\Slide;
use App\Models\Service;
use App\Models\Testimonial;
use Inertia\Inertia;

class HomeController extends Controller
{
    public function index()
    {
        // Retrieve slides and services data
        $slides = Slide::all();
        $services = Service::all();
        $articles = Article::all();
        $testimonials = Testimonial::where('Status', 'approved')->orderBy('DisplayOrder')->get();

        // Retrieve teachers data for landing page
        $teachers = \App\Models\Teacher::orderBy('name')->get();

        // Pass the data to the Home page component
        return Inertia::render('@public/Main/Landing', [
            'slides' => $slides,
            'services' => $services,
            'articles'=> $articles,
            'testimonials'=> $testimonials,
            'teachers' => $teachers,
        ]);
    }
}
