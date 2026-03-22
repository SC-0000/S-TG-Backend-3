<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Api\ApiController;
use App\Models\CommunicationTemplate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CommunicationTemplateController extends ApiController
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $orgId = $request->attributes->get('organization_id') ?: $user->current_organization_id;

        $query = CommunicationTemplate::forOrganization($orgId)
            ->with('creator:id,name')
            ->orderBy('name');

        if ($request->filled('category')) {
            $query->where('category', $request->input('category'));
        }

        if ($request->filled('channel')) {
            $query->forChannel($request->input('channel'));
        }

        if ($request->boolean('active_only', true)) {
            $query->active();
        }

        if ($request->filled('search')) {
            $query->where('name', 'like', '%' . $request->input('search') . '%');
        }

        $templates = $query->paginate($request->input('per_page', 20));

        return $this->paginated($templates, $templates->items());
    }

    public function store(Request $request): JsonResponse
    {
        $user = $request->user();
        $orgId = $request->attributes->get('organization_id') ?: $user->current_organization_id;

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'channel' => 'required|in:email,sms,whatsapp,multi',
            'subject' => 'nullable|string|max:255',
            'body_text' => 'required|string',
            'body_html' => 'nullable|string',
            'variables' => 'nullable|array',
            'category' => 'required|in:reminder,marketing,transactional,followup',
        ]);

        $template = CommunicationTemplate::create(array_merge($validated, [
            'organization_id' => $orgId,
            'created_by' => $user->id,
        ]));

        return $this->success($template, [], 201);
    }

    public function show(CommunicationTemplate $template): JsonResponse
    {
        $template->load('creator:id,name');
        return $this->success($template);
    }

    public function update(Request $request, CommunicationTemplate $template): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'string|max:255',
            'channel' => 'in:email,sms,whatsapp,multi',
            'subject' => 'nullable|string|max:255',
            'body_text' => 'string',
            'body_html' => 'nullable|string',
            'variables' => 'nullable|array',
            'category' => 'in:reminder,marketing,transactional,followup',
            'is_active' => 'boolean',
        ]);

        $template->update($validated);

        return $this->success($template->fresh());
    }

    public function destroy(CommunicationTemplate $template): JsonResponse
    {
        $template->delete();
        return $this->success(['message' => 'Template deleted.']);
    }
}
