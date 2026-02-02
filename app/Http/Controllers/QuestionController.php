<?php

namespace App\Http\Controllers;

use App\Models\Question;
use App\Models\Organization;
use App\Services\QuestionTypeRegistry;
use App\Support\ApiPagination;
use App\Support\ApiResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Http\UploadedFile;

class QuestionController extends Controller
{
    use ApiResponse;
    /**
     * Get route prefix based on user role
     */
    private function getRoutePrefix(): string
    {
        $user = Auth::user();
        return $user->hasRole('teacher') ? 'teacher' : 'admin';
    }
    
    public function index(Request $request)
    {
        $user = Auth::user();
        
        // Super admin can filter by organization, others see only their organization
        if ($user->hasRole('super_admin') && $request->filled('organization_id')) {
            $organizationId = $request->organization_id;
        } else {
            $organizationId = $user->current_organization_id;
        }
        
        $query = Question::with(['organization', 'creator'])
            ->when($organizationId, function($q) use ($organizationId) {
                return $q->forOrganization($organizationId);
            });

        // Apply filters
        if ($request->filled('type')) {
            $query->byType($request->type);
        }
        
        if ($request->filled('category')) {
            $query->byCategory($request->category);
        }
        
        if ($request->filled('grade')) {
            $query->where('grade', $request->grade);
        }
        
        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }
        
        if ($request->filled('difficulty_min') || $request->filled('difficulty_max')) {
            $query->byDifficulty($request->difficulty_min, $request->difficulty_max);
        }
        
        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function($q) use ($search) {
                $q->where('title', 'like', "%{$search}%")
                  ->orWhere('question_data->question_text', 'like', "%{$search}%")
                  ->orWhereJsonContains('tags', $search);
            });
        }

        $questions = $query->orderBy('created_at', 'desc')
            ->paginate(20)
            ->withQueryString();

        $questionTypes = QuestionTypeRegistry::getAvailableTypes();
        $categories = Question::distinct('category')->pluck('category')->filter()->values()->toArray();
        
        // Get organizations for super admin
        $organizations = null;
        if ($user->hasRole('super_admin')) {
            $organizations = Organization::orderBy('name')->get();
        }
        
        return Inertia::render('@admin/Questions/Index', [
            'questions' => $questions,
            'questionTypes' => $questionTypes,
            'categories' => $categories,
            'organizations' => $organizations,
            'filters' => $request->only(['type', 'category', 'grade', 'status', 'difficulty_min', 'difficulty_max', 'search', 'organization_id']),
        ]);
    }

    public function create()
    {
        $user = Auth::user();
        $organizations = [];
        
        if ($user->hasRole('super_admin')) {
            $organizations = Organization::all();
        } elseif ($user->current_organization_id) {
            $organizations = Organization::where('id', $user->current_organization_id)->get();
        }

        $questionTypes = QuestionTypeRegistry::getAvailableTypes();
        $categories = Question::distinct('category')->pluck('category')->filter()->values()->toArray();

        return Inertia::render('@admin/Questions/Create', [
            'organizations' => $organizations,
            'questionTypes' => $questionTypes,
            'categories' => $categories,
        ]);
    }

    public function store(Request $request)
    {
        $routePrefix = $this->getRoutePrefix();
        
        Log::info('Storing new question', ['user_id' => Auth::id()]);
        Log::info('Request data keys', array_keys($request->all()));
        Log::info('All uploaded files', array_keys($request->allFiles()));
        // Log::info('Question data before transformation', $request->question_data);
        Log::info('request data ', $request->all());
        $request->validate([
            'organization_id' => 'nullable|exists:organizations,id',
            'title' => 'required|string|max:255',
            'category' => 'nullable|string|max:100',
            'subcategory' => 'nullable|string|max:100',
            'grade' => 'required|string|max:50',
            'question_type' => ['required', 'string', Rule::in(array_keys(QuestionTypeRegistry::getAllTypes()))],
            'question_data' => 'required|array',
            'answer_schema' => 'required|array',
            'difficulty_level' => 'integer|min:1|max:10',
            'estimated_time_minutes' => 'nullable|integer|min:1',
            'marks' => 'numeric|min:0',
            'image_descriptions' => 'nullable|array',
            'image_descriptions.*' => 'nullable|string|max:500',
            'hints' => 'nullable|array',
            'solutions' => 'nullable|array',
            'tags' => 'nullable|array',
            'status' => 'in:draft,active,retired,under_review',
            // Image validation for question images
            'images.*' => 'nullable|image|max:2048',
        ]);
        Log::info('Validation passed');
        
        // Process image uploads using ArticleController pattern
        $questionData = $this->processQuestionImages($request->question_data, $request);
        
        // Transform question data for specific question types after image processing
        $questionData = $this->transformQuestionDataForValidation($questionData, $request->question_type, $request);
        
        // Post-process to update URLs in transformed data
        // if ($request->question_type === 'image_grid_mcq' && isset($questionData['images'])) {
        //     foreach ($questionData['images'] as $index => $image) {
        //         // If we have a placeholder but should have a real path from processing
        //         if (str_starts_with($image['url'], 'placeholder_') || str_starts_with($image['url'], 'pending_upload_')) {
        //             $fileKey = "question_data_image_options_{$index}_image";
        //             if ($request->hasFile($fileKey)) {
        //                 $path = $request->file($fileKey)->store('questions/images', 'public');
        //                 $questionData['images'][$index]['url'] = $path;
        //                 $questionData['images'][$index]['image_file'] = $path;
        //                 Log::info("Updated placeholder with actual path", ['index' => $index, 'path' => $path]);
        //             }
        //         }
        //     }
        // }
        
        // Validate question data structure
        $questionType = $request->question_type;
        $handler = QuestionTypeRegistry::getHandler($questionType);
        Log::info('Question type handler', ['handler' => $handler]);
        
        // Debug final question data before validation
        Log::info('🔍 FINAL QUESTION DATA before validation', [
            'question_type' => $questionType,
            'has_images' => isset($questionData['images']),
            'images_count' => isset($questionData['images']) ? count($questionData['images']) : 0,
            'question_data_keys' => array_keys($questionData)
        ]);
        
        if (isset($questionData['images'])) {
            foreach ($questionData['images'] as $i => $img) {
                Log::info("Image {$i} data", [
                    'id' => $img['id'] ?? 'missing',
                    'has_url' => isset($img['url']),
                    'has_image_file' => isset($img['image_file']),
                    'image_file_value' => $img['image_file'] ?? 'null',
                    'is_correct' => $img['is_correct'] ?? false
                ]);
            }
        }
        
        if (!$handler || !$handler::validate($questionData)) {
            Log::error('❌ Question data validation failed', [
                'handler_exists' => !!$handler,
                'question_data' => $questionData
            ]);
            return back()->withErrors(['question_data' => 'Invalid question data structure for this question type.']);
        }
        Log::info('✅ Question data structure validated');
        
        $question = Question::create([
            'organization_id' => $request->organization_id ?: Auth::user()->current_organization_id,
            'title' => $request->title,
            'category' => $request->category,
            'subcategory' => $request->subcategory,
            'grade' => $request->grade,
            'question_type' => $request->question_type,
            'question_data' => $questionData,
            'answer_schema' => $request->answer_schema,
            'difficulty_level' => $request->difficulty_level ?? 5,
            'estimated_time_minutes' => $request->estimated_time_minutes,
            'marks' => $request->marks ?? 1,
            'ai_metadata' => $request->ai_metadata ?? [],
            'image_descriptions' => $request->image_descriptions ?? [],
            'hints' => $request->hints ?? [],
            'solutions' => $request->solutions ?? [],
            'tags' => $request->tags ?? [],
            'status' => $request->status ?? 'draft',
            'created_by' => Auth::id(),
        ]);
        Log::info('Question created', ['question_id' => $question->id]);
        return redirect()->route("{$routePrefix}.questions.show", $question)
            ->with('success', 'Question created successfully.');
    }

    public function show(Question $question)
    {
        $question->load(['organization', 'creator']);
        
        return Inertia::render('@admin/Questions/Show', [
            'question' => $question,
            'rendered' => [
                'student_view' => $question->renderForStudent(),
                'admin_view' => $question->renderForAdmin(),
            ],
            'typeDefinition' => QuestionTypeRegistry::getTypeDefinition($question->question_type),
        ]);
    }

    public function edit(Question $question)
    {
        $user = Auth::user();
        $organizations = [];
        
        if ($user->hasRole('super_admin')) {
            $organizations = Organization::all();
        } elseif ($user->current_organization_id) {
            $organizations = Organization::where('id', $user->current_organization_id)->get();
        }

        $questionTypes = QuestionTypeRegistry::getAvailableTypes();
        $categories = Question::distinct('category')->pluck('category')->filter()->values()->toArray();

        // Transform question data back to frontend format
        $questionData = $question->toArray();
        if ($question->question_type === 'image_grid_mcq' && isset($questionData['question_data']['images'])) {
            // Reverse transformation: images → image_options for frontend
            $imageOptions = [];
            foreach ($questionData['question_data']['images'] as $img) {
                $imageOptions[] = [
                    'label' => $img['alt'] ?? $img['description'] ?? '',
                    'image_url' => isset($img['image_file']) ? '/' . $img['image_file'] : ($img['url'] ?? ''),
                    'image_file' => $img['image_file'] ?? null,
                    'is_correct' => $img['is_correct'] ?? false,
                ];
            }
            $questionData['question_data']['image_options'] = $imageOptions;
            unset($questionData['question_data']['images']);
        }

        if ($question->question_type === 'cloze') {
            $questionData['question_data']['cloze_text'] = $questionData['question_data']['cloze_text']
                ?? $questionData['question_data']['passage']
                ?? $questionData['question_data']['question_text']
                ?? '';

            if (empty($questionData['question_data']['blank_answers']) && !empty($questionData['question_data']['blanks'])) {
                $questionData['question_data']['blank_answers'] = array_map(
                    function ($blank) {
                        $answers = $blank['correct_answers'] ?? [];
                        if (is_array($answers) && $answers !== []) {
                            return (string) $answers[0];
                        }
                        return is_string($answers) ? $answers : '';
                    },
                    $questionData['question_data']['blanks']
                );
            }

            if (!isset($questionData['question_data']['case_sensitive'])) {
                $questionData['question_data']['case_sensitive'] = (bool) data_get(
                    $questionData,
                    'question_data.blanks.0.case_sensitive',
                    false
                );
            }
        }

        return Inertia::render('@admin/Questions/Edit', [
            'question' => $questionData,
            'organizations' => $organizations,
            'questionTypes' => $questionTypes,
            'categories' => $categories,
        ]);
    }

    public function update(Request $request, Question $question)
    {
        $routePrefix = $this->getRoutePrefix();
        
        Log::info('🔄 UPDATE STARTED', [
            'question_id' => $question->id,
            'user_id' => Auth::id(),
            'question_type' => $question->question_type
        ]);
        
        Log::info('📥 RAW REQUEST DATA', [
            'request_keys' => array_keys($request->all()),
            'has_question_data' => $request->has('question_data'),
            'question_data_type' => gettype($request->input('question_data')),
            'uploaded_files' => array_keys($request->allFiles())
        ]);
        
        Log::info('📊 FULL REQUEST DATA', $request->all());

        try {
            $request->validate([
                'organization_id' => 'nullable|exists:organizations,id',
                'title' => 'required|string|max:255',
                'category' => 'nullable|string|max:100',
                'subcategory' => 'nullable|string|max:100',
                'question_type' => ['required', 'string', Rule::in(array_keys(QuestionTypeRegistry::getAllTypes()))],
                'question_data' => 'required|array',
                'answer_schema' => 'required|array',
                'difficulty_level' => 'integer|min:1|max:10',
                'estimated_time_minutes' => 'nullable|integer|min:1',
                'marks' => 'numeric|min:0',
                'image_descriptions' => 'nullable|array',
                'image_descriptions.*' => 'nullable|string|max:500',
                'hints' => 'nullable|array',
                'solutions' => 'nullable|array',
                'tags' => 'nullable|array',
                'status' => 'in:draft,active,retired,under_review',
            ]);
            Log::info('✅ VALIDATION PASSED');
        } catch (\Illuminate\Validation\ValidationException $e) {
            Log::error('❌ VALIDATION FAILED', [
                'errors' => $e->errors(),
                'messages' => $e->getMessage()
            ]);
            throw $e;
        }

        // Transform question data for specific question types before validation
        Log::info('🔄 PROCESSING IMAGES - Before', [
            'question_data_keys' => array_keys($request->question_data),
            'has_image_options' => isset($request->question_data['image_options']),
            'has_images' => isset($request->question_data['images'])
        ]);
        
        $questionData = $this->processQuestionImages($request->question_data, $request);
        
        Log::info('✅ PROCESSING IMAGES - After', [
            'question_data_keys' => array_keys($questionData),
            'has_image_options' => isset($questionData['image_options']),
            'has_images' => isset($questionData['images'])
        ]);
        
        if (isset($questionData['image_options'])) {
            Log::info('📸 IMAGE OPTIONS STRUCTURE', [
                'count' => count($questionData['image_options']),
                'sample' => $questionData['image_options'][0] ?? 'none'
            ]);
        }
        
        Log::info('🔄 TRANSFORMING DATA - Before', [
            'question_type' => $request->question_type,
            'data_keys' => array_keys($questionData)
        ]);
        
        $questionData = $this->transformQuestionDataForValidation($questionData, $request->question_type, $request);
        
        Log::info('✅ TRANSFORMING DATA - After', [
            'data_keys' => array_keys($questionData),
            'has_images' => isset($questionData['images']),
            'has_image_options' => isset($questionData['image_options'])
        ]);
        
        if (isset($questionData['images'])) {
            Log::info('🖼️ IMAGES STRUCTURE', [
                'count' => count($questionData['images']),
                'sample' => $questionData['images'][0] ?? 'none'
            ]);
        }

        // Validate question data structure
        $questionType = $request->question_type;
        $handler = QuestionTypeRegistry::getHandler($questionType);
        
        Log::info('🔍 HANDLER CHECK', [
            'question_type' => $questionType,
            'handler_exists' => !!$handler,
            'handler_class' => $handler ?: 'none'

        ]);

        if (!$handler || !$handler::validate($questionData)) {
            Log::error('❌ HANDLER VALIDATION FAILED', [
                'handler_exists' => !!$handler,
                'question_type' => $questionType,
                'question_data' => $questionData
            ]);
            return back()->withErrors(['question_data' => 'Invalid question data structure for this question type.']);
        }
        
        Log::info('✅ HANDLER VALIDATION PASSED');

        $updateData = [
            'organization_id' => $request->organization_id ?: $question->organization_id,
            'title' => $request->title,
            'category' => $request->category,
            'subcategory' => $request->subcategory,
            'question_type' => $request->question_type,
            'question_data' => $questionData,
            'answer_schema' => $request->answer_schema,
            'difficulty_level' => $request->difficulty_level,
            'estimated_time_minutes' => $request->estimated_time_minutes,
            'marks' => $request->marks,
            'ai_metadata' => $request->ai_metadata ?? $question->ai_metadata,
            'image_descriptions' => $request->image_descriptions ?? [],
            'hints' => $request->hints ?? [],
            'solutions' => $request->solutions ?? [],
            'tags' => $request->tags ?? [],
            'status' => $request->status,
        ];
        
        Log::info('💾 UPDATE DATA PREPARED', [
            'question_data_keys' => array_keys($updateData['question_data']),
            'has_images' => isset($updateData['question_data']['images'])
        ]);
        
        try {
            $question->update($updateData);
            Log::info('✅ QUESTION UPDATED SUCCESSFULLY', ['question_id' => $question->id]);
        } catch (\Exception $e) {
            Log::error('❌ UPDATE FAILED', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            throw $e;
        }

        return redirect()->route("{$routePrefix}.questions.show", $question)
            ->with('success', 'Question updated successfully.');
    }

    public function destroy(Question $question)
    {
        $routePrefix = $this->getRoutePrefix();
        
        $question->delete();
        
        return redirect()->route("{$routePrefix}.questions.index")
            ->with('success', 'Question deleted successfully.');
    }

    public function duplicate(Question $question)
    {
        $routePrefix = $this->getRoutePrefix();
        
        $duplicate = $question->duplicate();
        
        return redirect()->route("{$routePrefix}.questions.edit", $duplicate)
            ->with('success', 'Question duplicated successfully.');
    }

    public function preview(Question $question)
    {
        return response()->json([
            'student_view' => $question->renderForStudent(),
            'admin_view' => $question->renderForAdmin(),
        ]);
    }

    public function getTypeDefaults(Request $request)
    {
        $request->validate(['type' => 'required|string']);
        
        $type = $request->type;
        
        if (!QuestionTypeRegistry::isValidType($type)) {
            return response()->json(['error' => 'Invalid question type'], 400);
        }
        
        return response()->json([
            'question_data' => QuestionTypeRegistry::getDefaultQuestionData($type),
            'answer_schema' => QuestionTypeRegistry::getDefaultAnswerSchema($type),
            'definition' => QuestionTypeRegistry::getTypeDefinition($type),
        ]);
    }

    /**
     * Process question images using ArticleController pattern - simple and direct
     */

    protected function processQuestionImages(array $questionData, Request $request): array
{
    Log::info('🔍 FILE PROCESSING: Starting', [
        'has_image_options' => isset($questionData['image_options']),
        'all_files' => array_keys($request->allFiles())
    ]);

    // Handle Image Grid MCQ images with NESTED structure (like Articles)
    if (isset($questionData['image_options']) && is_array($questionData['image_options'])) {
        Log::info('📋 Processing image options (nested structure)', ['count' => count($questionData['image_options'])]);
        
        foreach ($questionData['image_options'] as $index => $option) {
            // Check if image_file is a File object in the nested structure
            if (isset($option['image_file']) && $option['image_file'] instanceof UploadedFile) {
                try {
                    $file = $option['image_file'];
                    $stored = $file->store('questions/grid', 'public');
                    $fullPath = 'storage/' . $stored;
                    
                    // Replace File object with actual path
                    $questionData['image_options'][$index]['image_file'] = $fullPath;
                    
                    Log::info("✅ File stored (nested)", [
                        'index' => $index,
                        'path' => $fullPath
                    ]);

                    // Clean up data URL to save space
                    if (!empty($questionData['image_options'][$index]['image_url']) &&
                        str_starts_with((string)$questionData['image_options'][$index]['image_url'], 'data:')) {
                        unset($questionData['image_options'][$index]['image_url']);
                    }
                } catch (\Exception $e) {
                    Log::error("❌ File storage failed", [
                        'index' => $index,
                        'error' => $e->getMessage()
                    ]);
                }
            }
        }
    }

    // Handle MCQ question_image field (when it's an UploadedFile object)
    if (isset($questionData['question_image']) && $questionData['question_image'] instanceof UploadedFile) {
        try {
            $file = $questionData['question_image'];
            $stored = $file->store('questions/images', 'public');
            $fullPath = 'storage/' . $stored;
            
            $questionData['question_image'] = $fullPath;
            
            Log::info("✅ MCQ question image stored", ['path' => $fullPath]);
        } catch (\Exception $e) {
            Log::error("❌ MCQ question image storage failed", ['error' => $e->getMessage()]);
            // Set to empty object on failure
            $questionData['question_image'] = (object)[];
        }
    }

    // Handle main question image (fallback for direct file upload)
    if ($request->hasFile('question_image')) {
        try {
            $stored = $request->file('question_image')->store('questions/images', 'public');
            $questionData['question_image'] = 'storage/' . $stored;
            Log::info("✅ Main question image stored", ['path' => $stored]);
        } catch (\Exception $e) {
            Log::error("❌ Main question image storage failed", ['error' => $e->getMessage()]);
        }
    }

    Log::info('🔍 FILE PROCESSING: Completed');
    return $questionData;
}



    /**
     * API endpoint for searching questions with filters
     */
    public function searchApi(Request $request)
    {
        $user = Auth::user();
        $organizationId = $user->current_organization_id;

        $query = Question::query()
            ->where('organization_id', $organizationId)
            ->with(['creator:id,name', 'organization:id,name']);

        // Search by text
        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('title', 'like', "%{$search}%")
                  ->orWhere('category', 'like', "%{$search}%")
                  ->orWhere('subcategory', 'like', "%{$search}%")
                  ->orWhereJsonContains('tags', $search)
                  ->orWhereRaw("JSON_EXTRACT(question_data, '$.question_text') LIKE ?", ["%{$search}%"]);
            });
        }

        // Filter by question type
        if ($request->filled('type')) {
            $query->where('question_type', $request->type);
        }

        // Filter by category
        if ($request->filled('category')) {
            $query->where('category', $request->category);
        }

        // Filter by grade
        if ($request->filled('grade')) {
            $query->where('grade', $request->grade);
        }

        // Filter by status
        if ($request->filled('status')) {
            $query->where('status', $request->status);
        } else {
            // Default to active questions only
            $query->where('status', 'active');
        }

        // Filter by difficulty range
        if ($request->filled('difficulty_min')) {
            $query->where('difficulty_level', '>=', $request->difficulty_min);
        }
        if ($request->filled('difficulty_max')) {
            $query->where('difficulty_level', '<=', $request->difficulty_max);
        }

        // Quick filters
        if ($request->filled('quick_filter')) {
            switch ($request->quick_filter) {
                case 'recent':
                    $query->where('created_at', '>=', now()->subDays(7));
                    break;
                case 'my_questions':
                    $query->where('created_by', $user->id);
                    break;
                case 'high_difficulty':
                    $query->where('difficulty_level', '>=', 7);
                    break;
            }
        }

        // Sorting
        $sortBy = $request->get('sort_by', 'created_at');
        $sortOrder = $request->get('sort_order', 'desc');
        $query->orderBy($sortBy, $sortOrder);

        // Pagination
        $perPage = $request->get('per_page', 20);
        $questions = $query->paginate($perPage);

        return response()->json([
            'questions' => $questions,
            'questionTypes' => QuestionTypeRegistry::getAllTypes(),
        ]);
    }

    /**
     * API endpoint for batch fetching questions by IDs (for lesson preview & player)
     */
    public function batchFetch(Request $request)
    {
        $request->validate([
            'ids' => 'required|string',
        ]);

        $user = Auth::user();
        $organizationId = $user->current_organization_id;
        
        // Parse comma-separated IDs
        $ids = array_filter(array_map('intval', explode(',', $request->ids)));

        if (empty($ids)) {
            return response()->json([]);
        }

        // Fetch questions with organization filter
        $questions = Question::whereIn('id', $ids)
            ->where('organization_id', $organizationId)
            ->with(['creator:id,name', 'organization:id,name'])
            ->get();

        return response()->json($questions);
    }

    /**
     * API v1 endpoint for searching questions with filters (JSON envelope).
     */
    public function searchApiV1(Request $request)
    {
        $user = Auth::user();
        $organizationId = $user->current_organization_id;

        $query = Question::query()
            ->where('organization_id', $organizationId)
            ->with(['creator:id,name', 'organization:id,name']);

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('title', 'like', "%{$search}%")
                    ->orWhere('category', 'like', "%{$search}%")
                    ->orWhere('subcategory', 'like', "%{$search}%")
                    ->orWhereJsonContains('tags', $search)
                    ->orWhereRaw("JSON_EXTRACT(question_data, '$.question_text') LIKE ?", ["%{$search}%"]);
            });
        }

        if ($request->filled('type')) {
            $query->where('question_type', $request->type);
        }

        if ($request->filled('category')) {
            $query->where('category', $request->category);
        }

        if ($request->filled('grade')) {
            $query->where('grade', $request->grade);
        }

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        } else {
            $query->where('status', 'active');
        }

        if ($request->filled('difficulty_min')) {
            $query->where('difficulty_level', '>=', $request->difficulty_min);
        }
        if ($request->filled('difficulty_max')) {
            $query->where('difficulty_level', '<=', $request->difficulty_max);
        }

        if ($request->filled('quick_filter')) {
            switch ($request->quick_filter) {
                case 'recent':
                    $query->where('created_at', '>=', now()->subDays(7));
                    break;
                case 'my_questions':
                    $query->where('created_by', $user->id);
                    break;
                case 'high_difficulty':
                    $query->where('difficulty_level', '>=', 7);
                    break;
            }
        }

        $sortBy = $request->get('sort_by', 'created_at');
        $sortOrder = $request->get('sort_order', 'desc');
        $query->orderBy($sortBy, $sortOrder);

        $questions = $query->paginate(ApiPagination::perPage($request, 20));

        return $this->paginated(
            $questions,
            $questions->items(),
            ['question_types' => QuestionTypeRegistry::getAllTypes()]
        );
    }

    /**
     * API v1 endpoint for batch fetching questions by IDs (JSON envelope).
     */
    public function batchFetchV1(Request $request)
    {
        $request->validate([
            'ids' => 'required|string',
        ]);

        $user = Auth::user();
        $organizationId = $user->current_organization_id;

        $ids = array_filter(array_map('intval', explode(',', $request->ids)));

        if (empty($ids)) {
            return $this->success([]);
        }

        $questions = Question::whereIn('id', $ids)
            ->where('organization_id', $organizationId)
            ->with(['creator:id,name', 'organization:id,name'])
            ->get();

        return $this->success($questions);
    }

    /**
     * API endpoint for quick question creation during assessment building
     */
    public function quickCreateApi(Request $request)
    {
        $user = Auth::user();
        $organizationId = $user->current_organization_id;
        Log::info('Quick create question request', ['user_id' => $user->id, 'organization_id' => $organizationId]);
        Log::info('Request data', $request->all());
        Log::info('Request files', array_keys($request->allFiles()));
        
        $request->validate([
            'title' => 'required|string|max:255',
            'question_type' => 'required|string|in:' . implode(',', array_keys(QuestionTypeRegistry::getAllTypes())),
            'question_data' => 'required|array', // NOW expects array from bracket notation
            'answer_schema' => 'nullable|array', // NOW expects array from bracket notation
            'category' => 'nullable|string|max:100',
            'subcategory' => 'nullable|string|max:100',
            'difficulty_level' => 'nullable|integer|min:1|max:10',
            'estimated_time_minutes' => 'nullable|integer|min:1',
            'marks' => 'required|numeric|min:0',
            'tags' => 'nullable|array', // NOW expects array from bracket notation
            'status' => 'nullable|in:draft,active,under_review,retired',
        ]);

        // Laravel auto-parses bracket notation into arrays - use them directly!
        $questionData = $request->all();
        $questionData['organization_id'] = $organizationId;
        $questionData['created_by'] = $user->id;
        $questionData['status'] = $questionData['status'] ?? 'active';
        $questionData['difficulty_level'] = $questionData['difficulty_level'] ?? 5;
        $questionData['estimated_time_minutes'] = $questionData['estimated_time_minutes'] ?? 5;
        $questionData['tags'] = $questionData['tags'] ?? [];
        $questionData['answer_schema'] = $questionData['answer_schema'] ?? [];
        
        Log::info('✅ Received question_data structure', ['keys' => array_keys($questionData['question_data'])]);

        // Handle file uploads in question_data
        $questionData['question_data'] = $this->processQuestionImages($questionData['question_data'], $request);
        
        // Transform question data to match expected format (image_options → images, etc.)
        $questionData['question_data'] = $this->transformQuestionDataForValidation($questionData['question_data'], $questionData['question_type'], $request);
        
        Log::info('✅ Transformed question_data structure', ['keys' => array_keys($questionData['question_data'])]);

        $question = Question::create($questionData);

        return response()->json([
            'question' => $question->load(['creator:id,name', 'organization:id,name']),
            'message' => 'Question created successfully and added to question bank!'
        ], 201);
    }

    /**
     * Transform question data for validation based on question type
     */
    protected function transformQuestionDataForValidation(array $questionData, string $questionType, Request $request): array
    {
        switch ($questionType) {
            case 'image_grid_mcq':
                Log::info('Transforming question data for validation', [
                    'question_type' => $questionType,
                    'original_data_keys' => array_keys($questionData)
                ]);
                return $this->transformImageGridMcqData($questionData, $request);
                
            case 'ordering':
                return $this->transformOrderingData($questionData);
                
            case 'cloze':
                return $this->transformClozeData($questionData);
                
            default:
                // For all other question types, return data as-is
                return $questionData;
        }
    }

    /**
     * Transform ordering question data from frontend format to validation format
     */
    protected function transformOrderingData(array $questionData): array
    {
        // Convert order_items to items if present
        if (isset($questionData['order_items']) && is_array($questionData['order_items'])) {
            $questionData['items'] = $questionData['order_items'];
            $questionData['correct_order'] = $questionData['order_items']; // Set the original order as correct
            unset($questionData['order_items']);
        }

        // Convert string boolean values to actual booleans
        if (isset($questionData['shuffle'])) {
            $questionData['shuffle'] = (bool) ($questionData['shuffle'] === '1' || 
                $questionData['shuffle'] === 1 || 
                $questionData['shuffle'] === true);
        }

        return $questionData;
    }

    /**
     * Transform cloze question data from frontend format to validation format
     */
    protected function transformClozeData(array $questionData): array
    {
        // Handle field name mapping from frontend to backend
        if (isset($questionData['cloze_text'])) {
            $questionData['passage'] = $questionData['cloze_text'];
            unset($questionData['cloze_text']);
        }

        // Transform blank_answers to blanks structure
        if (isset($questionData['blank_answers']) && is_array($questionData['blank_answers'])) {
            $blanks = [];
            foreach ($questionData['blank_answers'] as $index => $answer) {
                $blanks[] = [
                    'id' => 'blank' . ($index + 1),
                    'correct_answers' => is_array($answer) ? $answer : [$answer],
                    'case_sensitive' => false,
                    'accept_partial' => false,
                    'placeholder' => '',
                    'max_length' => 50,
                ];
            }
            $questionData['blanks'] = $blanks;
            unset($questionData['blank_answers']);
        }

        // Convert boolean string values to actual booleans for case sensitivity
        if (isset($questionData['case_sensitive'])) {
            $questionData['case_sensitive'] = (bool) ($questionData['case_sensitive'] === '1' || 
                $questionData['case_sensitive'] === 1 || 
                $questionData['case_sensitive'] === true);
        }

        // Convert boolean values for blanks if they exist
        if (isset($questionData['blanks']) && is_array($questionData['blanks'])) {
            foreach ($questionData['blanks'] as $index => $blank) {
                if (isset($blank['case_sensitive'])) {
                    $questionData['blanks'][$index]['case_sensitive'] = (bool) ($blank['case_sensitive'] === '1' || 
                        $blank['case_sensitive'] === 1 || 
                        $blank['case_sensitive'] === true);
                }
                
                if (isset($blank['accept_partial'])) {
                    $questionData['blanks'][$index]['accept_partial'] = (bool) ($blank['accept_partial'] === '1' || 
                        $blank['accept_partial'] === 1 || 
                        $blank['accept_partial'] === true);
                }
            }
        }

        return $questionData;
    }

    /**
     * Transform image grid MCQ data from frontend format to validation format
     */
    protected function transformImageGridMcqData(array $questionData, Request $request): array
{
    if (isset($questionData['image_options']) && is_array($questionData['image_options'])) {
        $images = [];
        foreach ($questionData['image_options'] as $i => $opt) {
            $images[] = [
                'id'          => 'img_' . ($i + 1),
                'alt'         => $opt['label'] ?? ('Image ' . ($i + 1)),
                'description' => $opt['label'] ?? ('Image ' . ($i + 1)),
                'url'         => $opt['image_url'] ?? ('placeholder_' . $i), // keep author URL or placeholder
                'image_file'  => $opt['image_file'] ?? null,                 // <-- stored "storage/..." path
                'is_correct'  => !empty($opt['is_correct']),
            ];
        }
        $questionData['images'] = $images;
        unset($questionData['image_options']);
    }

    // normalize flags
    if (isset($questionData['allow_multiple'])) {
        $questionData['allow_multiple'] = (bool)$questionData['allow_multiple'];
    }
    if (isset($questionData['shuffle_images'])) {
        $questionData['shuffle_images'] = (bool)$questionData['shuffle_images'];
    }
    if (isset($questionData['grid_columns'])) {
        $questionData['grid_columns'] = (int)$questionData['grid_columns'];
    }

    return $questionData;
}


}
