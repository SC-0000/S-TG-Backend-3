<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Faq;
use App\Models\Article;
use Inertia\Inertia;
use Illuminate\Support\Str;

class FaqController extends Controller
{
    public function portalIndex()
    {
        $faqs = Faq::where('published', true)->get();
        $articles = Article::all();
        return Inertia::render('@parent/Main/Faq', [
            'faqs' => $faqs,
            'articles' => $articles,
        ]);
    }
    public function create()
    {
        // Render the Inertia component for creating an FAQ.
        return Inertia::render('@admin/Faqs/CreateFaq');
    }

    public function store(Request $request)
    {
        // Validate the incoming FAQ data.
        $validatedData = $request->validate([
            'question'  => 'required|string',
            'answer'    => 'required|string',
            'category'  => 'nullable|string|max:255',
            'tags'      => 'nullable|string', // Comma-separated string to be converted
            'published' => 'required|boolean',
            // Image will be processed as a file upload (optional)
        ]);

        // Convert comma-separated tags to an array.
        if (!empty($validatedData['tags'])) {
            $validatedData['tags'] = array_map('trim', explode(',', $validatedData['tags']));
        } else {
            $validatedData['tags'] = null;
        }

        // Process image upload if a file is provided.
        if ($request->hasFile('image')) {
            // Store image in the "faqs" directory (ensure you've run "php artisan storage:link")
            $validatedData['image'] = $request->file('image')->store('faqs', 'public');
        } else {
            $validatedData['image'] = null;
        }

        // Set additional fields.
        $validatedData['id'] = (string) Str::uuid();
        $validatedData['author_id'] = 'test-author-uuid';

        // $validatedData['author_id'] = auth()->id() ?? (string) Str::uuid();

        // Create the FAQ entry.
        Faq::create($validatedData);

        return redirect()->route('faqs.index')
                         ->with('success', 'FAQ created successfully!');
    }
  
    public function index()
{
    $faqs = Faq::all();
    return Inertia::render('@admin/Faqs/IndexFaq', ['faqs' => $faqs]);
}

public function show($id)
{
    $faq = Faq::findOrFail($id);
    return Inertia::render('@admin/Faqs/ShowFaq', ['faq' => $faq]);
}

public function edit($id)
{
    $faq = Faq::findOrFail($id);
    return Inertia::render('@admin/Faqs/EditFaq', ['faq' => $faq]);
}

public function update(Request $request, $id)
{
    $faq = Faq::findOrFail($id);
    
    $validatedData = $request->validate([
        'question' => 'required|string',
        'answer'   => 'required|string',
        'category' => 'nullable|string',
        'tags'     => 'nullable|string', // We'll convert this to an array.
        'published'=> 'required|boolean',
        // 'image' validation as needed (e.g., 'nullable|image|max:2048')
    ]);
    
    // Convert comma-separated tags into an array.
    if (!empty($validatedData['tags'])) {
        $validatedData['tags'] = array_map('trim', explode(',', $validatedData['tags']));
    } else {
        $validatedData['tags'] = null;
    }
    
    // Process new image if uploaded.
    if ($request->hasFile('image')) {
        $path = $request->file('image')->store('faqs', 'public');
        $validatedData['image'] = $path;
    }
    
    $faq->update($validatedData);
    
    return redirect()->route('faqs.show', $faq->id)
                     ->with('success', 'FAQ updated successfully!');
}

public function destroy($id)
{
    $faq = Faq::findOrFail($id);
    $faq->delete();
    return redirect()->route('faqs.index')
                     ->with('success', 'FAQ deleted successfully!');
}

}
