<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Api\ApiController;
use App\Models\Organization;
use App\Models\Product;
use App\Support\ApiPagination;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ProductController extends ApiController
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        if (!$user) {
            return $this->error('Unauthenticated.', [], 401);
        }

        $orgId = $request->attributes->get('organization_id') ?: $user->current_organization_id;
        if ($user->isSuperAdmin() && $request->filled('organization_id')) {
            $orgId = $request->integer('organization_id');
        }

        $query = Product::query()
            ->when($orgId, fn ($q) => $q->forOrganization($orgId))
            ->orderBy('name');

        $products = $query->paginate(ApiPagination::perPage($request, 20));

        $organizations = null;
        if ($user->isSuperAdmin()) {
            $organizations = Organization::orderBy('name')->get(['id', 'name']);
        }

        $data = $products->getCollection()->map(fn (Product $product) => $this->mapProduct($product))->all();

        return $this->paginated($products, $data, [
            'organizations' => $organizations,
            'filters' => $request->only(['organization_id']),
        ]);
    }

    public function show(Request $request, Product $product): JsonResponse
    {
        if ($response = $this->ensureScope($request, $product)) {
            return $response;
        }

        $product->load('discounts');

        return $this->success($this->mapProduct($product, true));
    }

    public function store(Request $request): JsonResponse
    {
        $user = $request->user();
        if (!$user) {
            return $this->error('Unauthenticated.', [], 401);
        }

        $request->merge(array_map(fn ($v) => $v === '' ? null : $v, $request->all()));

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'price' => ['required', 'numeric'],
            'stock_status' => ['required', Rule::in(['in_stock', 'out_of_stock', 'pre_order'])],
            'category' => ['required', 'string', 'max:255'],
            'image' => ['nullable', 'image', 'max:2048'],
            'related_lesson_id' => ['nullable', 'integer', 'exists:lessons,id'],
            'discount' => ['nullable', 'numeric'],
            'discounts' => ['nullable', 'array'],
            'discounts.*.discount_type' => ['required_with:discounts', Rule::in(['percentage', 'fixed'])],
            'discounts.*.discount_value' => ['required_with:discounts', 'numeric'],
            'discounts.*.start_date' => ['required_with:discounts', 'date'],
            'discounts.*.end_date' => ['required_with:discounts', 'date', 'after:discounts.*.start_date'],
            'discounts.*.status' => ['required_with:discounts', Rule::in(['active', 'expired'])],
            'organization_id' => ['nullable', 'integer', 'exists:organizations,id'],
        ]);

        if ($request->hasFile('image')) {
            $validated['image_path'] = $request->file('image')->store('products', 'public');
        }

        $validated['organization_id'] = $user->isSuperAdmin() && !empty($validated['organization_id'])
            ? $validated['organization_id']
            : $user->current_organization_id;

        $product = Product::create($validated);

        if (!empty($validated['discounts'])) {
            foreach ($validated['discounts'] as $discount) {
                $product->discounts()->create($discount);
            }
        }

        $product->load('discounts');

        return $this->success($this->mapProduct($product, true), [], 201);
    }

    public function update(Request $request, Product $product): JsonResponse
    {
        if ($response = $this->ensureScope($request, $product)) {
            return $response;
        }

        $request->merge(array_map(fn ($v) => $v === '' ? null : $v, $request->all()));

        $validated = $request->validate([
            'name' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'price' => ['nullable', 'numeric'],
            'stock_status' => ['nullable', Rule::in(['in_stock', 'out_of_stock', 'pre_order'])],
            'category' => ['nullable', 'string', 'max:255'],
            'image' => ['nullable', 'image', 'max:2048'],
            'related_lesson_id' => ['nullable', 'integer', 'exists:lessons,id'],
            'discount' => ['nullable', 'numeric'],
            'discounts' => ['nullable', 'array'],
            'discounts.*.discount_type' => ['required_with:discounts', Rule::in(['percentage', 'fixed'])],
            'discounts.*.discount_value' => ['required_with:discounts', 'numeric'],
            'discounts.*.start_date' => ['required_with:discounts', 'date'],
            'discounts.*.end_date' => ['required_with:discounts', 'date', 'after:discounts.*.start_date'],
            'discounts.*.status' => ['required_with:discounts', Rule::in(['active', 'expired'])],
        ]);

        if ($request->hasFile('image')) {
            $validated['image_path'] = $request->file('image')->store('products', 'public');
        }

        $product->update($validated);

        $product->discounts()->delete();
        if (!empty($validated['discounts'])) {
            foreach ($validated['discounts'] as $discount) {
                $product->discounts()->create($discount);
            }
        }

        $product->load('discounts');

        return $this->success($this->mapProduct($product, true));
    }

    public function destroy(Request $request, Product $product): JsonResponse
    {
        if ($response = $this->ensureScope($request, $product)) {
            return $response;
        }

        $product->delete();

        return $this->success(['message' => 'Product deleted successfully.']);
    }

    private function ensureScope(Request $request, Product $product): ?JsonResponse
    {
        $user = $request->user();
        if (!$user) {
            return $this->error('Unauthenticated.', [], 401);
        }

        $orgId = $request->attributes->get('organization_id') ?: $user->current_organization_id;
        if ($user->isSuperAdmin() && $request->filled('organization_id')) {
            $orgId = $request->integer('organization_id');
        }

        if ($orgId && (int) $product->organization_id !== (int) $orgId) {
            return $this->error('Not found.', [], 404);
        }

        return null;
    }

    private function mapProduct(Product $product, bool $withDiscounts = false): array
    {
        $data = [
            'id' => $product->id,
            'organization_id' => $product->organization_id,
            'name' => $product->name,
            'display_name' => $product->display_name,
            'description' => $product->description,
            'price' => $product->price,
            'stock_status' => $product->stock_status,
            'category' => $product->category,
            'image_path' => $product->image_path,
            'related_lesson_id' => $product->related_lesson_id,
            'discount' => $product->discount,
        ];

        if ($withDiscounts) {
            $data['discounts'] = $product->discounts?->map(fn ($discount) => [
                'id' => $discount->id,
                'discount_type' => $discount->discount_type,
                'discount_value' => $discount->discount_value,
                'start_date' => $discount->start_date,
                'end_date' => $discount->end_date,
                'status' => $discount->status,
            ])->values()->all() ?? [];
        }

        return $data;
    }
}
