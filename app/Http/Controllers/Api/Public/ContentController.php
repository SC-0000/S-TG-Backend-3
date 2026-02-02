<?php

namespace App\Http\Controllers\Api\Public;

use App\Http\Controllers\Api\ApiController;
use App\Models\Alert;
use App\Models\Article;
use App\Models\Faq;
use App\Models\Milestone;
use App\Models\Slide;
use App\Models\Teacher;
use App\Models\Testimonial;
use App\Support\ApiPagination;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class ContentController extends ApiController
{
    private function resolveOrgId(Request $request): ?int
    {
        $orgId = $request->header('X-Organization-Id') ?? $request->query('organization_id');
        return $orgId ? (int) $orgId : null;
    }

    private function cacheTtl(): int
    {
        return (int) config('api.public_cache_ttl', 60);
    }

    public function home(Request $request): JsonResponse
    {
        $orgId = $this->resolveOrgId($request);
        $cacheKey = 'public:home:' . ($orgId ?? 'all');
        $ttl = $this->cacheTtl();

        $payload = $ttl > 0
            ? Cache::remember($cacheKey, $ttl, function () use ($orgId) {
                $slides = Slide::query()
                    ->when($orgId, fn ($q) => $q->where('organization_id', $orgId))
                    ->where('status', 'active')
                    ->orderBy('order')
                    ->get();

                $articles = Article::query()
                    ->when($orgId, fn ($q) => $q->where('organization_id', $orgId))
                    ->where('scheduled_publish_date', '<=', now())
                    ->orderByDesc('scheduled_publish_date')
                    ->get();

                $testimonials = Testimonial::query()
                    ->when($orgId, fn ($q) => $q->forOrganization($orgId))
                    ->where('Status', 'Approved')
                    ->orderBy('DisplayOrder')
                    ->get();

                $teachers = Teacher::orderBy('name')->get();

                return [
                    'slides' => $slides->map(fn ($slide) => [
                        'id' => $slide->slide_id,
                        'title' => $slide->title,
                        'content' => $slide->content,
                        'template_id' => $slide->template_id,
                        'order' => $slide->order,
                        'tags' => $slide->tags,
                        'schedule' => $slide->schedule,
                        'status' => $slide->status,
                        'images' => $slide->images,
                    ]),
                    'articles' => $articles->map(fn ($article) => [
                        'id' => $article->id,
                        'title' => $article->title,
                        'description' => $article->description,
                        'thumbnail' => $article->thumbnail,
                        'scheduled_publish_date' => $article->scheduled_publish_date,
                        'category' => $article->category,
                        'tag' => $article->tag,
                        'author' => $article->author,
                    ]),
                    'testimonials' => $testimonials->map(fn ($t) => [
                        'id' => $t->TestimonialID,
                        'name' => $t->UserName,
                        'message' => $t->Message,
                        'rating' => $t->Rating,
                        'status' => $t->Status,
                        'display_order' => $t->DisplayOrder,
                    ]),
                    'teachers' => $teachers->map(fn ($teacher) => [
                        'id' => $teacher->id,
                        'name' => $teacher->name,
                        'title' => $teacher->title,
                        'role' => $teacher->role,
                        'bio' => $teacher->bio,
                        'category' => $teacher->category,
                        'specialties' => $teacher->specialties,
                        'image_path' => $teacher->image_path,
                    ]),
                ];
            })
            : (function () use ($orgId) {
                $slides = Slide::query()
                    ->when($orgId, fn ($q) => $q->where('organization_id', $orgId))
                    ->where('status', 'active')
                    ->orderBy('order')
                    ->get();

                $articles = Article::query()
                    ->when($orgId, fn ($q) => $q->where('organization_id', $orgId))
                    ->where('scheduled_publish_date', '<=', now())
                    ->orderByDesc('scheduled_publish_date')
                    ->get();

                $testimonials = Testimonial::query()
                    ->when($orgId, fn ($q) => $q->forOrganization($orgId))
                    ->where('Status', 'Approved')
                    ->orderBy('DisplayOrder')
                    ->get();

                $teachers = Teacher::orderBy('name')->get();

                return [
                    'slides' => $slides->map(fn ($slide) => [
                        'id' => $slide->slide_id,
                        'title' => $slide->title,
                        'content' => $slide->content,
                        'template_id' => $slide->template_id,
                        'order' => $slide->order,
                        'tags' => $slide->tags,
                        'schedule' => $slide->schedule,
                        'status' => $slide->status,
                        'images' => $slide->images,
                    ]),
                    'articles' => $articles->map(fn ($article) => [
                        'id' => $article->id,
                        'title' => $article->title,
                        'description' => $article->description,
                        'thumbnail' => $article->thumbnail,
                        'scheduled_publish_date' => $article->scheduled_publish_date,
                        'category' => $article->category,
                        'tag' => $article->tag,
                        'author' => $article->author,
                    ]),
                    'testimonials' => $testimonials->map(fn ($t) => [
                        'id' => $t->TestimonialID,
                        'name' => $t->UserName,
                        'message' => $t->Message,
                        'rating' => $t->Rating,
                        'status' => $t->Status,
                        'display_order' => $t->DisplayOrder,
                    ]),
                    'teachers' => $teachers->map(fn ($teacher) => [
                        'id' => $teacher->id,
                        'name' => $teacher->name,
                        'title' => $teacher->title,
                        'role' => $teacher->role,
                        'bio' => $teacher->bio,
                        'category' => $teacher->category,
                        'specialties' => $teacher->specialties,
                        'image_path' => $teacher->image_path,
                    ]),
                ];
            })();

        return $this->success($payload);
    }

    public function about(Request $request): JsonResponse
    {
        $orgId = $this->resolveOrgId($request);
        $cacheKey = 'public:about:' . ($orgId ?? 'all');
        $ttl = $this->cacheTtl();

        $payload = $ttl > 0
            ? Cache::remember($cacheKey, $ttl, function () use ($orgId) {
                $milestones = Milestone::query()
                    ->when($orgId, fn ($q) => $q->forOrganization($orgId))
                    ->orderBy('DisplayOrder')
                    ->get();

                $testimonials = Testimonial::query()
                    ->when($orgId, fn ($q) => $q->forOrganization($orgId))
                    ->where('Status', 'Approved')
                    ->orderBy('DisplayOrder')
                    ->get();

                $faqs = Faq::query()
                    ->when($orgId, fn ($q) => $q->where('organization_id', $orgId))
                    ->where('published', true)
                    ->get();

                return [
                    'milestones' => $milestones->map(fn ($m) => [
                        'id' => $m->MilestoneID,
                        'title' => $m->Title,
                        'date' => $m->Date?->toDateString(),
                        'description' => $m->Description,
                        'image' => $m->Image,
                        'display_order' => $m->DisplayOrder,
                    ]),
                    'testimonials' => $testimonials->map(fn ($t) => [
                        'id' => $t->TestimonialID,
                        'name' => $t->UserName,
                        'message' => $t->Message,
                        'rating' => $t->Rating,
                        'status' => $t->Status,
                        'display_order' => $t->DisplayOrder,
                    ]),
                    'faqs' => $faqs->map(fn ($faq) => [
                        'id' => $faq->id,
                        'question' => $faq->question,
                        'answer' => $faq->answer,
                        'category' => $faq->category,
                        'tags' => $faq->tags,
                        'image' => $faq->image,
                    ]),
                ];
            })
            : (function () use ($orgId) {
                $milestones = Milestone::query()
                    ->when($orgId, fn ($q) => $q->forOrganization($orgId))
                    ->orderBy('DisplayOrder')
                    ->get();

                $testimonials = Testimonial::query()
                    ->when($orgId, fn ($q) => $q->forOrganization($orgId))
                    ->where('Status', 'Approved')
                    ->orderBy('DisplayOrder')
                    ->get();

                $faqs = Faq::query()
                    ->when($orgId, fn ($q) => $q->where('organization_id', $orgId))
                    ->where('published', true)
                    ->get();

                return [
                    'milestones' => $milestones->map(fn ($m) => [
                        'id' => $m->MilestoneID,
                        'title' => $m->Title,
                        'date' => $m->Date?->toDateString(),
                        'description' => $m->Description,
                        'image' => $m->Image,
                        'display_order' => $m->DisplayOrder,
                    ]),
                    'testimonials' => $testimonials->map(fn ($t) => [
                        'id' => $t->TestimonialID,
                        'name' => $t->UserName,
                        'message' => $t->Message,
                        'rating' => $t->Rating,
                        'status' => $t->Status,
                        'display_order' => $t->DisplayOrder,
                    ]),
                    'faqs' => $faqs->map(fn ($faq) => [
                        'id' => $faq->id,
                        'question' => $faq->question,
                        'answer' => $faq->answer,
                        'category' => $faq->category,
                        'tags' => $faq->tags,
                        'image' => $faq->image,
                    ]),
                ];
            })();

        return $this->success($payload);
    }

    public function contact(Request $request): JsonResponse
    {
        $orgId = $this->resolveOrgId($request);
        $cacheKey = 'public:contact:' . ($orgId ?? 'all');
        $ttl = $this->cacheTtl();

        $payload = $ttl > 0
            ? Cache::remember($cacheKey, $ttl, function () use ($orgId) {
                $faqs = Faq::query()
                    ->when($orgId, fn ($q) => $q->where('organization_id', $orgId))
                    ->where('published', true)
                    ->get();

                return [
                    'faqs' => $faqs->map(fn ($faq) => [
                        'id' => $faq->id,
                        'question' => $faq->question,
                        'answer' => $faq->answer,
                        'category' => $faq->category,
                        'tags' => $faq->tags,
                        'image' => $faq->image,
                    ]),
                ];
            })
            : (function () use ($orgId) {
                $faqs = Faq::query()
                    ->when($orgId, fn ($q) => $q->where('organization_id', $orgId))
                    ->where('published', true)
                    ->get();

                return [
                    'faqs' => $faqs->map(fn ($faq) => [
                        'id' => $faq->id,
                        'question' => $faq->question,
                        'answer' => $faq->answer,
                        'category' => $faq->category,
                        'tags' => $faq->tags,
                        'image' => $faq->image,
                    ]),
                ];
            })();

        return $this->success($payload);
    }

    public function pages(Request $request): JsonResponse
    {
        $home = $this->home($request)->getData(true)['data'] ?? null;
        $about = $this->about($request)->getData(true)['data'] ?? null;
        $contact = $this->contact($request)->getData(true)['data'] ?? null;

        return $this->success([
            'home' => $home,
            'about' => $about,
            'contact' => $contact,
        ]);
    }

    public function articles(Request $request): JsonResponse
    {
        $orgId = $this->resolveOrgId($request);

        $query = Article::query()
            ->when($orgId, fn ($q) => $q->where('organization_id', $orgId))
            ->where('scheduled_publish_date', '<=', now());

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('title', 'like', "%{$search}%")
                    ->orWhere('description', 'like', "%{$search}%")
                    ->orWhere('author', 'like', "%{$search}%");
            });
        }

        if ($request->filled('category')) {
            $query->where('category', $request->category);
        }

        if ($request->filled('tag')) {
            $query->where('tag', $request->tag);
        }

        $articles = $query->orderByDesc('scheduled_publish_date')
            ->paginate(ApiPagination::perPage($request));

        $data = $articles->getCollection()->map(fn ($article) => [
            'id' => $article->id,
            'title' => $article->title,
            'description' => $article->description,
            'thumbnail' => $article->thumbnail,
            'scheduled_publish_date' => $article->scheduled_publish_date,
            'category' => $article->category,
            'tag' => $article->tag,
            'author' => $article->author,
        ])->all();

        return $this->paginated($articles, $data);
    }

    public function articleShow(Request $request, Article $article): JsonResponse
    {
        $orgId = $this->resolveOrgId($request);
        if ($orgId && (int) $article->organization_id !== (int) $orgId) {
            return $this->error('Not found.', [], 404);
        }
        if ($article->scheduled_publish_date && $article->scheduled_publish_date->isFuture()) {
            return $this->error('Not found.', [], 404);
        }

        return $this->success([
            'id' => $article->id,
            'title' => $article->title,
            'description' => $article->description,
            'body_type' => $article->body_type,
            'article_template' => $article->article_template,
            'author' => $article->author,
            'scheduled_publish_date' => $article->scheduled_publish_date,
            'thumbnail' => $article->thumbnail,
            'author_photo' => $article->author_photo,
            'pdf' => $article->pdf,
            'images' => $article->images,
            'sections' => $article->sections ?? null,
            'titles' => $article->titles ?? null,
            'bodies' => $article->bodies ?? null,
            'key_attributes' => $article->key_attributes ?? null,
            'category' => $article->category,
            'tag' => $article->tag,
        ]);
    }

    public function faqs(Request $request): JsonResponse
    {
        $orgId = $this->resolveOrgId($request);

        $query = Faq::query()
            ->when($orgId, fn ($q) => $q->where('organization_id', $orgId))
            ->where('published', true);

        if ($request->filled('category')) {
            $query->where('category', $request->category);
        }

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('question', 'like', "%{$search}%")
                    ->orWhere('answer', 'like', "%{$search}%");
            });
        }

        $faqs = $query->orderBy('created_at', 'desc')
            ->paginate(ApiPagination::perPage($request));

        $data = $faqs->getCollection()->map(fn ($faq) => [
            'id' => $faq->id,
            'question' => $faq->question,
            'answer' => $faq->answer,
            'category' => $faq->category,
            'tags' => $faq->tags,
            'image' => $faq->image,
        ])->all();

        return $this->paginated($faqs, $data);
    }

    public function faqShow(Request $request, Faq $faq): JsonResponse
    {
        $orgId = $this->resolveOrgId($request);
        if ($orgId && (int) $faq->organization_id !== (int) $orgId) {
            return $this->error('Not found.', [], 404);
        }

        if (!$faq->published) {
            return $this->error('Not found.', [], 404);
        }

        return $this->success([
            'id' => $faq->id,
            'question' => $faq->question,
            'answer' => $faq->answer,
            'category' => $faq->category,
            'tags' => $faq->tags,
            'image' => $faq->image,
        ]);
    }

    public function alerts(Request $request): JsonResponse
    {
        $orgId = $this->resolveOrgId($request);

        $query = Alert::query()
            ->when($orgId, fn ($q) => $q->where('organization_id', $orgId));

        if ($request->filled('type')) {
            $query->where('type', $request->type);
        }

        if ($request->filled('priority')) {
            $query->where('priority', $request->priority);
        }

        if ($request->boolean('active', true)) {
            $now = now();
            $query->where(function ($q) use ($now) {
                $q->whereNull('start_time')->orWhere('start_time', '<=', $now);
            })->where(function ($q) use ($now) {
                $q->whereNull('end_time')->orWhere('end_time', '>=', $now);
            });
        }

        $alerts = $query->orderByDesc('start_time')
            ->paginate(ApiPagination::perPage($request));

        $data = $alerts->getCollection()->map(fn ($alert) => [
            'id' => $alert->alert_id,
            'title' => $alert->title,
            'message' => $alert->message,
            'type' => $alert->type,
            'priority' => $alert->priority,
            'start_time' => $alert->start_time?->toISOString(),
            'end_time' => $alert->end_time?->toISOString(),
            'pages' => $alert->pages,
            'additional_context' => $alert->additional_context,
        ])->all();

        return $this->paginated($alerts, $data);
    }

    public function alertShow(Request $request, string $alertId): JsonResponse
    {
        $alert = Alert::where('alert_id', $alertId)->firstOrFail();
        $orgId = $this->resolveOrgId($request);
        if ($orgId && (int) $alert->organization_id !== (int) $orgId) {
            return $this->error('Not found.', [], 404);
        }

        return $this->success([
            'id' => $alert->alert_id,
            'title' => $alert->title,
            'message' => $alert->message,
            'type' => $alert->type,
            'priority' => $alert->priority,
            'start_time' => $alert->start_time?->toISOString(),
            'end_time' => $alert->end_time?->toISOString(),
            'pages' => $alert->pages,
            'additional_context' => $alert->additional_context,
        ]);
    }

    public function slides(Request $request): JsonResponse
    {
        $orgId = $this->resolveOrgId($request);

        $query = Slide::query()
            ->when($orgId, fn ($q) => $q->where('organization_id', $orgId))
            ->where('status', 'active');

        $slides = $query->orderBy('order')
            ->paginate(ApiPagination::perPage($request));

        $data = $slides->getCollection()->map(fn ($slide) => [
            'id' => $slide->slide_id,
            'title' => $slide->title,
            'content' => $slide->content,
            'template_id' => $slide->template_id,
            'order' => $slide->order,
            'tags' => $slide->tags,
            'schedule' => $slide->schedule,
            'status' => $slide->status,
            'images' => $slide->images,
        ])->all();

        return $this->paginated($slides, $data);
    }

    public function slideShow(Request $request, string $slideId): JsonResponse
    {
        $slide = Slide::where('slide_id', $slideId)->firstOrFail();
        $orgId = $this->resolveOrgId($request);
        if ($orgId && (int) $slide->organization_id !== (int) $orgId) {
            return $this->error('Not found.', [], 404);
        }

        if ($slide->status !== 'active') {
            return $this->error('Not found.', [], 404);
        }

        return $this->success([
            'id' => $slide->slide_id,
            'title' => $slide->title,
            'content' => $slide->content,
            'template_id' => $slide->template_id,
            'order' => $slide->order,
            'tags' => $slide->tags,
            'schedule' => $slide->schedule,
            'status' => $slide->status,
            'images' => $slide->images,
        ]);
    }

    public function testimonials(Request $request): JsonResponse
    {
        $orgId = $this->resolveOrgId($request);

        $query = Testimonial::query()
            ->when($orgId, fn ($q) => $q->forOrganization($orgId))
            ->where('Status', 'Approved');

        $testimonials = $query->orderBy('DisplayOrder')
            ->paginate(ApiPagination::perPage($request));

        $data = $testimonials->getCollection()->map(fn ($t) => [
            'id' => $t->TestimonialID,
            'name' => $t->UserName,
            'message' => $t->Message,
            'rating' => $t->Rating,
            'status' => $t->Status,
            'display_order' => $t->DisplayOrder,
            'submitted_at' => $t->SubmissionDate?->toISOString(),
            'attachments' => $t->Attachments,
        ])->all();

        return $this->paginated($testimonials, $data);
    }

    public function testimonialShow(Request $request, string $testimonialId): JsonResponse
    {
        $testimonial = Testimonial::where('TestimonialID', $testimonialId)->firstOrFail();
        $orgId = $this->resolveOrgId($request);
        if ($orgId && (int) $testimonial->organization_id !== (int) $orgId) {
            return $this->error('Not found.', [], 404);
        }

        if ($testimonial->Status !== 'Approved') {
            return $this->error('Not found.', [], 404);
        }

        return $this->success([
            'id' => $testimonial->TestimonialID,
            'name' => $testimonial->UserName,
            'message' => $testimonial->Message,
            'rating' => $testimonial->Rating,
            'status' => $testimonial->Status,
            'display_order' => $testimonial->DisplayOrder,
            'submitted_at' => $testimonial->SubmissionDate?->toISOString(),
            'attachments' => $testimonial->Attachments,
        ]);
    }
}
