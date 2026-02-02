<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Product;
use Inertia\Inertia;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Auth;

class ProductController extends Controller
{
    public function portalIndex(Request $request)
    {
        $user = $request->user();
        $orgId = $user?->current_organization_id;

        // Fetch all products
        $products = Product::when($orgId, fn($q) => $q->forOrganization($orgId))->get();

        // Fetch all children for the current user (for filtering)
        $allChildren = $user->role === 'parent'
            ? $user->children()->get(['id', 'child_name', 'year_group'])
            : \App\Models\Child::get(['id', 'child_name', 'year_group']);

        // Lessons services: _type in ['lesson', 'bundle']
        $lessonsServices = \App\Models\Service::whereIn('_type', ['lesson', 'bundle'])
            ->when($orgId, fn($q) => $q->visibleToOrg($orgId))
            ->with([
                'lessons:id,title,service_id',
                'children:id'
            ])
            ->get()
            ->map(function ($svc) {
                $svc->child_ids = $svc->children
                    ->pluck('id')
                    ->map(fn($id) => (string) $id)
                    ->values();
                unset($svc->children);
                return $svc;
            });

        // Assessments services: _type in ['bundle', 'assessment']
        $assessmentsServices = \App\Models\Service::whereIn('_type', ['bundle', 'assessment'])
            ->when($orgId, fn($q) => $q->visibleToOrg($orgId))
            ->with([
                'assessments',
                'children:id'
            ])
            ->get()
            ->map(function ($svc) {
                $svc->child_ids = $svc->children
                    ->pluck('id')
                    ->map(fn($id) => (string) $id)
                    ->values();
                unset($svc->children);
                return $svc;
            });

        return Inertia::render('@parent/Main/ProductsHub', [
            'products' => $products,
            'lessonsServices' => $lessonsServices,
            'assessmentsServices' => $assessmentsServices,
            // 'allChildren' => $allChildren,
            'csrf_token' => csrf_token(),
        ]);
    }

    public function index()
    {
        $user = auth()->user();
        $query = Product::query();

        if ($user?->role === 'super_admin' && request()->filled('organization_id')) {
            $query->forOrganization(request()->organization_id);
        } elseif ($user?->role !== 'super_admin' && $user?->current_organization_id) {
            $query->forOrganization($user->current_organization_id);
        }

        $products = $query->get();
        $organizations = $user?->role === 'super_admin'
            ? \App\Models\Organization::orderBy('name')->get()
            : null;

        return Inertia::render('@admin/Products/Index', [
            'products' => $products,
            'organizations' => $organizations,
            'filters' => request()->only('organization_id'),
        ]);
    }

    public function create()
    {
        return Inertia::render('@admin/Products/Create');
    }

    public function store(Request $request)
    {
        // Convert empty strings to null if not already handled by middleware:
        $request->merge(array_map(fn($v) => $v === '' ? null : $v, $request->all()));

        $validatedData = $request->validate([
            'name'              => 'required|string|max:255',
            'description'       => 'nullable|string',
            'price'             => 'required|numeric',
            'stock_status'      => 'required|in:in_stock,out_of_stock,pre_order',
            'category'          => 'required|string|max:255',
            'image'             => 'nullable|image|max:2048',
            'related_lesson_id' => 'nullable|integer|exists:lessons,id',
            'discount'          => 'nullable|numeric',

            // Optional nested discounts
            'discounts'                   => 'nullable|array',
            'discounts.*.discount_type'   => 'required_with:discounts|in:percentage,fixed',
            'discounts.*.discount_value'  => 'required_with:discounts|numeric',
            'discounts.*.start_date'      => 'required_with:discounts|date',
            'discounts.*.end_date'        => 'required_with:discounts|date|after:discounts.*.start_date',
            'discounts.*.status'          => 'required_with:discounts|in:active,expired',
        ]);

        if ($request->hasFile('image')) {
            $path = $request->file('image')->store('products', 'public');
            $validatedData['image_path'] = $path;
        }

        $validatedData['organization_id'] = Auth::user()?->current_organization_id;

        $product = Product::create($validatedData);

        if (!empty($validatedData['discounts'])) {
            foreach ($validatedData['discounts'] as $discount) {
                $product->discounts()->create($discount);
            }
        }

        return redirect()->route('products.index')->with('success', 'Product created successfully!');
    }

    public function edit($id)
    {
        $orgId = auth()->user()?->current_organization_id;
        $product = Product::with('discounts')
            ->when($orgId, fn($q) => $q->forOrganization($orgId))
            ->findOrFail($id);
        return Inertia::render('@admin/Products/Edit', ['product' => $product]);
    }

    public function update(Request $request, $id)
    {
        $orgId = auth()->user()?->current_organization_id;
        $product = Product::when($orgId, fn($q) => $q->forOrganization($orgId))->findOrFail($id);

        // Convert empty strings to null if needed.
        $request->merge(array_map(fn($v) => $v === '' ? null : $v, $request->all()));

        $validatedData = $request->validate([
            'name'              => 'nullable|string|max:255',
            'description'       => 'nullable|string',
            'price'             => 'nullable|numeric',
            'stock_status'      => 'nullable|in:in_stock,out_of_stock,pre_order',
            'category'          => 'nullable|string|max:255',
            'image'             => 'nullable|image|max:2048',
            'related_lesson_id' => 'nullable|integer|exists:lessons,id',
            'discount'          => 'nullable|numeric',

            // Nested discounts rules...
            'discounts'                   => 'nullable|array',
            'discounts.*.discount_type'   => 'required_with:discounts|in:percentage,fixed',
            'discounts.*.discount_value'  => 'required_with:discounts|numeric',
            'discounts.*.start_date'      => 'required_with:discounts|date',
            'discounts.*.end_date'        => 'required_with:discounts|date|after:discounts.*.start_date',
            'discounts.*.status'          => 'required_with:discounts|in:active,expired',
        ]);

        if ($request->hasFile('image')) {
            $path = $request->file('image')->store('products', 'public');
            $validatedData['image_path'] = $path;
        }

        $product->update($validatedData);

        // Process discounts if needed...
        $product->discounts()->delete();
        if (!empty($validatedData['discounts'])) {
            foreach ($validatedData['discounts'] as $discount) {
                $product->discounts()->create($discount);
            }
        }

        return redirect()->route('products.show', $product->id)
                         ->with('success', 'Product updated successfully!');
    }

    public function show($id)
    {
        $orgId = auth()->user()?->current_organization_id;
        $product = Product::with('discounts')
            ->when($orgId, fn($q) => $q->forOrganization($orgId))
            ->findOrFail($id);
        return Inertia::render('@admin/Products/Show', ['product' => $product]);
    }
    public function destroy($id)
{
    $orgId = auth()->user()?->current_organization_id;
    $product = Product::when($orgId, fn($q) => $q->forOrganization($orgId))->findOrFail($id);
    $product->delete();

    return redirect()->route('products.index')
                     ->with('success', 'Product deleted successfully!');
}

}
