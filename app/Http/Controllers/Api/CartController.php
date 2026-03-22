<?php

namespace App\Http\Controllers\Api;

use App\Helpers\CartSession;
use App\Http\Requests\Api\Cart\CartAddRequest;
use App\Http\Requests\Api\Cart\CartFlexibleRequest;
use App\Http\Requests\Api\Cart\CartUpdateRequest;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Product;
use App\Models\Promotion;
use App\Models\Service;
use App\Services\FlexibleServiceAccessService;
use App\Services\PromotionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CartController extends ApiController
{
    public function index(Request $request): JsonResponse
    {
        $cart = CartSession::current()->load('items.service', 'items.product');

        return $this->respondWithCart($cart);
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

        return $this->respondWithCart($cart, 201);
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

        return $this->respondWithCart($cart, 201);
    }

    public function update(CartUpdateRequest $request, CartItem $item): JsonResponse
    {
        $cart = CartSession::current();
        if ((int) $item->cart_id !== (int) $cart->id) {
            return $this->error('Not found.', [], 404);
        }

        $item->update(['quantity' => $request->validated()['quantity']]);

        $cart->load('items.service', 'items.product');

        return $this->respondWithCart($cart);
    }

    public function destroy(Request $request, CartItem $item): JsonResponse
    {
        $cart = CartSession::current();
        if ((int) $item->cart_id !== (int) $cart->id) {
            return $this->error('Not found.', [], 404);
        }

        $item->delete();

        $cart->load('items.service', 'items.product');

        return $this->respondWithCart($cart);
    }

    public function applyPromotion(Request $request, PromotionService $promoService): JsonResponse
    {
        $request->validate(['code' => 'required|string|max:50']);

        $cart = CartSession::current()->load('items.service', 'items.product');
        $cartData = $this->buildCartItems($cart);
        $subtotal = collect($cartData)->sum('line_total');

        $user = $request->user();
        $orgId = $user?->current_organization_id;

        $result = $promoService->validateCode(
            strtoupper($request->input('code')),
            $subtotal,
            $user?->id ?? 0,
            $orgId,
            $cartData
        );

        if (!$result['valid']) {
            return $this->error($result['error'], [], 422);
        }

        $cart->promotion_code = strtoupper($request->input('code'));
        $cart->save();

        return $this->respondWithCart($cart);
    }

    public function removePromotion(Request $request): JsonResponse
    {
        $cart = CartSession::current()->load('items.service', 'items.product');
        $cart->promotion_code = null;
        $cart->save();

        return $this->respondWithCart($cart);
    }

    public function validatePromotion(Request $request, PromotionService $promoService): JsonResponse
    {
        $request->validate(['code' => 'required|string|max:50']);

        $cart = CartSession::current()->load('items.service', 'items.product');
        $cartData = $this->buildCartItems($cart);
        $subtotal = collect($cartData)->sum('line_total');

        $user = $request->user();
        $orgId = $user?->current_organization_id;

        $result = $promoService->validateCode(
            strtoupper($request->input('code')),
            $subtotal,
            $user?->id ?? 0,
            $orgId,
            $cartData
        );

        if (!$result['valid']) {
            return $this->error($result['error'], [], 422);
        }

        $discount = $promoService->calculateDiscount($result['promotion'], $subtotal, $cartData);

        return $this->success([
            'valid' => true,
            'promotion' => [
                'name' => $result['promotion']->name,
                'code' => $result['promotion']->code,
                'discount_type' => $result['promotion']->discount_type,
                'discount_value' => $result['promotion']->discount_value,
            ],
            'discount_amount' => $discount,
            'new_total' => max(0, $subtotal - $discount),
        ]);
    }

    private function buildCartItems(Cart $cart): array
    {
        return $cart->items->map(function (CartItem $item) {
            return [
                'type' => $item->service_id ? 'service' : 'product',
                'id' => $item->service_id ?? $item->product_id,
                'line_total' => $item->quantity * $item->price,
            ];
        })->toArray();
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

        $discount = 0;
        $discountDescription = null;
        $promotionCode = $cart->promotion_code;

        if ($promotionCode) {
            $promotion = Promotion::where('code', $promotionCode)->active()->first();
            if ($promotion) {
                $promoService = app(PromotionService::class);
                $cartData = $this->buildCartItems($cart);
                $discount = $promoService->calculateDiscount($promotion, $subtotal, $cartData);
                $discountDescription = $promotion->name;
            } else {
                // Promotion no longer valid, clear it
                $cart->promotion_code = null;
                $cart->save();
                $promotionCode = null;
            }
        }

        return [
            'id' => $cart->id,
            'items' => $items,
            'subtotal' => $subtotal,
            'discount' => $discount,
            'discount_description' => $discountDescription,
            'promotion_code' => $promotionCode,
            'total' => max(0, $subtotal - $discount),
        ];
    }

    private function respondWithCart(Cart $cart, int $status = 200): JsonResponse
    {
        $response = $this->success($this->mapCart($cart), [], $status);

        if ($cart->cart_token) {
            $response->headers->set('X-Cart-Token', $cart->cart_token);
        }

        return $response;
    }
}
