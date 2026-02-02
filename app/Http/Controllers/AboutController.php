<?php

namespace App\Http\Controllers;

use App\Models\Faq;
use Illuminate\Http\Request;
use App\Models\Milestone;
use App\Models\Testimonial;
use Inertia\Inertia;

class AboutController extends Controller
{
    /**
     * Show the About Us page with timeline and testimonials.
     */
    public function index()
    {
        $milestones   = Milestone::orderBy('DisplayOrder')->get();
        $testimonials = Testimonial::where('Status', 'Approved')
                                   ->orderBy('DisplayOrder')
                                   ->get();
        $faqs= Faq::where('published', true)->get();
        // where('Published', 'Yes')
                                //    ->orderBy('DisplayOrder')

        return Inertia::render('@public/Main/AboutUs', [
            'milestones'   => $milestones,
            'testimonials' => $testimonials,
            'faqs'         => $faqs,
        ]);
    }
}
