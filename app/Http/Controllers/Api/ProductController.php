<?php

namespace App\Http\Controllers\Api;

use App\Models\Product;
use App\Support\ApiPagination;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProductController extends ApiController
{
    private function resolveOrgId(Request $request): ?int
    {
        $orgId = $request->header('X-Organization-Id')
            ?? $request->query('organization_id')
            ?? $request->attributes->get('organization_id');

        return $orgId ? (int) $orgId : null;
    }

    public function index(Request $request): JsonResponse
    {
        $orgId = $this->resolveOrgId($request);

        $query = Product::query()
            ->when($orgId, fn ($q) => $q->where('organization_id', $orgId));

        if ($request->filled('category')) {
            $query->where('category', $request->category);
        }

        if ($request->filled('stock_status')) {
            $query->where('stock_status', $request->stock_status);
        }

        $products = $query->orderBy('name')
            ->paginate(ApiPagination::perPage($request));

        $data = $products->getCollection()->map(fn ($product) => $this->mapProduct($product))->all();

        return $this->paginated($products, $data);
    }

    public function show(Request $request, Product $product): JsonResponse
    {
        $orgId = $this->resolveOrgId($request);
        if ($orgId && (int) $product->organization_id !== (int) $orgId) {
            return $this->error('Not found.', [], 404);
        }

        return $this->success($this->mapProduct($product));
    }

    private function mapProduct(Product $product): array
    {
        return [
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
    }
}
