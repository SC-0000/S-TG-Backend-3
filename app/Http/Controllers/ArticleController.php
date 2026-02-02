<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Article;
use App\Models\Organization;
use Inertia\Inertia;
 
class ArticleController extends Controller
{
    /**
     * Admin: Display a listing of articles for management
     */
    public function adminIndex(Request $request)
    {
        $user = auth()->user();
        
        $query = Article::query();
        
        // Super admin: optional organization filtering
        if ($user->role === 'super_admin' && $request->filled('organization_id')) {
            $query->where('organization_id', $request->organization_id);
        } 
        // Regular users: filter by their organization
        elseif ($user->role !== 'super_admin' && $user->current_organization_id) {
            $query->where('organization_id', $user->current_organization_id);
        }
        
        // Get organizations for super admin
        $organizations = null;
        if ($user->role === 'super_admin') {
            $organizations = Organization::orderBy('name')->get();
        }
        
        $articles = $query->orderBy('scheduled_publish_date', 'desc')->get();
        
        return Inertia::render('@admin/Articles/Index', [
            'articles' => $articles,
            'organizations' => $organizations,
            'filters' => $request->only('organization_id'),
        ]);
    }

    public function create()
{
    $organizations = Organization::where('status', 'active')->get();
    return Inertia::render('@admin/Articles/Create', [
        'organizations' => $organizations
    ]);
}

public function store(Request $request)
{
    // Validate required and optional fields.
    $validatedData = $request->validate([
        'organization_id'         => 'required|exists:organizations,id',
        'category'                => 'required|string',
        'tag'                     => 'required|string',
        'name'                    => 'required|string|unique:articles',
        'title'                   => 'required|string',
        'description'             => 'required|string',
        'body_type'               => 'required|in:pdf,template',
        'article_template'        => 'nullable|string|max:255',
        'author'                  => 'required|string',
        'scheduled_publish_date'  => 'required|date',
        // Remove strict JSON validation so empty strings can pass.
        'titles'                  => 'nullable',
        'bodies'                  => 'nullable',
        'key_attributes'          => 'nullable',
        'thumbnail' => 'nullable|image|max:2048',
'author_photo' => 'nullable|image|max:2048',
'pdf' => 'nullable|file|mimes:pdf|max:10000', // adjust mimes and max size as needed
// For multiple images, you can validate as array and each image as an image file.
'images.*' => 'nullable|image|max:2048',
'sections'            => 'required',   
    ]);
    if ($request->hasFile('thumbnail')) {
        $validatedData['thumbnail'] = $request->file('thumbnail')->store('articles/thumbnails', 'public');
    }
    
    if ($request->hasFile('author_photo')) {
        $validatedData['author_photo'] = $request->file('author_photo')->store('articles/authors', 'public');
    }
    
    if ($request->hasFile('pdf')) {
        $validatedData['pdf'] = $request->file('pdf')->store('articles/pdfs', 'public');
    }
    
    // For images (if multiple)
    if ($request->hasFile('images')) {
        $imagePaths = [];
        foreach ($request->file('images') as $image) {
            $imagePaths[] = $image->store('articles/images', 'public');
        }
        $validatedData['images'] = $imagePaths;
    }
    

    // List of fields that should be JSON.
    $jsonFields = ['titles', 'bodies', 'images', 'key_attributes'];

    // For each JSON field, convert the string to an array if it's not empty.
    foreach (['titles', 'bodies', 'key_attributes'] as $field) {
        if (!empty($validatedData[$field])) {
            $validatedData[$field] = array_map('trim',
                preg_split('/\s*,\s*/', $validatedData[$field])
            );
        } else {
            $validatedData[$field] = null;
        }
    }
Article::create($validatedData);


    return redirect()->route('articles.index')
                     ->with('success', 'Article created successfully!');
}

public function index()
    {
        $articles = Article::all();
        return Inertia::render('@public/Articles/IndexArticle', ['articles' => $articles]);
    }

    // Display a single article.
    public function show($id)
    {
        $article = Article::findOrFail($id);

        // four most‑recent articles except the one we’re viewing
        $recentPosts = Article::whereKeyNot($id)
            ->latest('scheduled_publish_date')
            ->take(4)
            ->get(['id', 'title', 'thumbnail']);

        return Inertia::render('@public/Articles/ShowArticle', [
            'article'     => $article,        // sections already included
            'recentPosts' => $recentPosts,
        ]);
    }

    // Show the edit form for an article.
    public function edit($id)
    {
        $article = Article::findOrFail($id);
        $organizations = Organization::where('status', 'active')->get();
        return Inertia::render('@admin/Articles/EditArticle', [
            'article' => $article,
            'organizations' => $organizations
        ]);
    }

    // Update an existing article.
    public function update(Request $request, $id)
    {
        $article = Article::findOrFail($id);
    
        $validatedData = $request->validate([
            'organization_id'         => 'required|exists:organizations,id',
            'category'                => 'required|string',
            'tag'                     => 'required|string',
            'name'                    => 'required|string|unique:articles,name,'.$article->id,
            'title'                   => 'required|string',
            'thumbnail'               => 'nullable|image|max:2048',
            'description'             => 'required|string',
            'body_type'               => 'required|in:pdf,template',
            'pdf'                     => 'nullable|file|mimes:pdf|max:10000',
            'article_template'        => 'nullable|string|max:255',
            'author'                  => 'required|string',
            'author_photo'            => 'nullable|image|max:2048',
            'scheduled_publish_date'  => 'required|date',
            'titles'                  => 'nullable|string',
            'bodies'                  => 'nullable|string',
            'images'                  => 'nullable|array',
            'images.*'                => 'image|max:2048',
            'key_attributes'          => 'nullable|string',
            'sections'               => 'required|array|min:1',
        'sections.*.header'      => 'nullable|string|max:255',
        'sections.*.body'        => 'required|string',
        ]);
    
        // Process JSON fields (convert comma-separated strings to arrays)
        foreach (['titles', 'key_attributes'] as $field) {
            if (!empty($validatedData[$field])) {
                $validatedData[$field] = array_map('trim', explode(',', $validatedData[$field]));
            } else {
                $validatedData[$field] = null;
            }
        }
    
        // Process multi-file upload for images if new files are provided
        if ($request->hasFile('images')) {
            $imagePaths = [];
            foreach ($request->file('images') as $image) {
                $imagePaths[] = $image->store('articles/images', 'public');
            }
            $validatedData['images'] = $imagePaths;
        } else {
            // Retain existing images if no new images are uploaded
            $validatedData['images'] = $article->images;
        }
    
        // Process single file uploads and retain existing if not updated
        if ($request->hasFile('thumbnail')) {
            $validatedData['thumbnail'] = $request->file('thumbnail')->store('articles/thumbnails', 'public');
        } else {
            $validatedData['thumbnail'] = $article->thumbnail;
        }
    
        if ($request->hasFile('pdf')) {
            $validatedData['pdf'] = $request->file('pdf')->store('articles/pdfs', 'public');
        } else {
            $validatedData['pdf'] = $article->pdf;
        }
    
        if ($request->hasFile('author_photo')) {
            $validatedData['author_photo'] = $request->file('author_photo')->store('articles/author_photos', 'public');
        } else {
            $validatedData['author_photo'] = $article->author_photo;
        }
    
        $article->update($validatedData);
    
        return redirect()->route('articles.show', $article->id)
                         ->with('success', 'Article updated successfully!');
    }
    

    // Delete an article.
    public function destroy($id)
    {
        $article = Article::findOrFail($id);
        $article->delete();

        return redirect()->route('articles.index')
                         ->with('success', 'Article deleted successfully!');
    }

}
