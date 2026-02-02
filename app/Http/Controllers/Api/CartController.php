<?php

namespace App\Http\Controllers\Api;

use App\Helpers\CartSession;
use App\Http\Requests\Api\Cart\CartAddRequest;
use App\Http\Requests\Api\Cart\CartFlexibleRequest;
use App\Http\Requests\Api\Cart\CartUpdateRequest;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Product;
use App\Models\Service;
use App\Services\FlexibleServiceAccessService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CartController extends ApiController
{
    public function index(Request $request): JsonResponse
    {
        $cart = CartSession::current()->load('items.service', 'items.product');

        return $this->success($this->mapCart($cart));
    }

    public function store(CartAddRequest $request): JsonResponse
    {
        $data = $request->validated();
        $type = $data['type'];
        $id = (int) $data['id'];
        $qty = (int) ($data['quantity'] ?? 1);

        if ($type === 'product') {
            $buyable = Product::find($id);
            $column = 'product_id';
        } else {
            $buyable = Service::find($id);
            $column = 'service_id';
        }

        if (! $buyable) {
            return $this->error(ucfirst($type) . ' not found.', [], 404);
        }

        $cart = CartSession::current();
        $item = $cart->items()->where($column, $id)->first();

        if ($item) {
            $item->quantity += $qty;
            $item->save();
        } else {
            $cart->items()->create([
                $column => $id,
                'quantity' => $qty,
                'price' => $buyable->price,
            ]);
        }

        $cart->load('items.service', 'items.product');

        return $this->success($this->mapCart($cart), [], 201);
    }

    public function storeFlexible(CartFlexibleRequest $request, FlexibleServiceAccessService $service): JsonResponse
    {
        $data = $request->validated();
        $flexible = Service::findOrFail($data['service_id']);

        if (! $flexible->isFlexibleService()) {
            return $this->error('This service is not a flexible service.', [], 400);
        }

        $validation = $service->validateSelections(
            $flexible,
            $data['selected_lessons'] ?? [],
            $data['selected_assessments'] ?? []
        );

        if (! $validation['valid']) {
            return $this->error($validation['message'] ?? 'Invalid selections.', [], 422);
        }

        $cart = CartSession::current();
        $existingItem = $cart->items()->where('service_id', $flexible->id)->first();

        $metadata = [
            'selected_lessons' => $data['selected_lessons'] ?? [],
            'selected_assessments' => $data['selected_assessments'] ?? [],
        ];

        if ($existingItem) {
            $existingItem->metadata = $metadata;
            $existingItem->save();
        } else {
            $cart->items()->create([
                'service_id' => $flexible->id,
                'quantity' => 1,
                'price' => $flexible->price,
                'metadata' => $metadata,
            ]);
        }

        $cart->load('items.service', 'items.product');

        return $this->success($this->mapCart($cart), [], 201);
    }

    public function update(CartUpdateRequest $request, CartItem $item): JsonResponse
    {
        $cart = CartSession::current();
        if ((int) $item->cart_id !== (int) $cart->id) {
            return $this->error('Not found.', [], 404);
        }

        $item->update(['quantity' => $request->validated()['quantity']]);

        $cart->load('items.service', 'items.product');

        return $this->success($this->mapCart($cart));
    }

    public function destroy(Request $request, CartItem $item): JsonResponse
    {
        $cart = CartSession::current();
        if ((int) $item->cart_id !== (int) $cart->id) {
            return $this->error('Not found.', [], 404);
        }

        $item->delete();

        $cart->load('items.service', 'items.product');

        return $this->success($this->mapCart($cart));
    }

    private function mapCart(Cart $cart): array
    {
        $items = $cart->items->map(function (CartItem $item) {
            $buyable = $item->service ?? $item->product;

            return [
                'id' => $item->id,
                'type' => $item->service_id ? 'service' : 'product',
                'quantity' => $item->quantity,
                'price' => $item->price,
                'line_total' => $item->quantity * $item->price,
                'metadata' => $item->metadata,
                'item' => $buyable ? [
                    'id' => $buyable->id,
                    'name' => $buyable->display_name ?? $buyable->service_name ?? $buyable->name,
                    'price' => $buyable->price ?? null,
                ] : null,
            ];
        })->values();

        $subtotal = $items->sum('line_total');

        return [
            'id' => $cart->id,
            'items' => $items,
            'subtotal' => $subtotal,
        ];
    }
}
