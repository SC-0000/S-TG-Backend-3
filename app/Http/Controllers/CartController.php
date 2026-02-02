<?php 
// app/Http/Controllers/CartController.php
namespace App\Http\Controllers;

use App\Helpers\CartSession;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Product;
use App\Models\Service; // Assuming you are adding services to the cart
use Illuminate\Http\Request;

class CartController extends Controller
{
    public function getCart()
    {
        $cart = CartSession::current()->load('items.service','items.product');
        return response()->json($cart);
    }

     public function addToCart(Request $request, $type, $id)
    {
        // Determine which model to use
        if ($type === 'product') {
            $buyable = Product::find($id);
            $column  = 'product_id';
        } else {
            $buyable = Service::find($id);
            $column  = 'service_id';
        }

        if (! $buyable) {
            return response()->json(['error' => ucfirst($type) . ' not found.'], 404);
        }

        $cart = CartSession::current();

        // See if already in cart
        $item = $cart->items()->where($column, $id)->first();

        if ($item) {
            $item->quantity++;
            $item->save();
        } else {
            $cart->items()->create([
                $column   => $id,
                'quantity'=> 1,
                'price'   => $buyable->price,
            ]);
        }

        return response()->json($cart->load('items.service', 'items.product'));
    }

    /**
     * Add a flexible service with selections to cart
     */
    public function addFlexibleService(Request $request)
    {
        $data = $request->validate([
            'service_id' => 'required|integer|exists:services,id',
            'selected_lessons' => 'nullable|array',
            'selected_lessons.*' => 'integer|exists:live_sessions,id',
            'selected_assessments' => 'nullable|array',
            'selected_assessments.*' => 'integer|exists:assessments,id',
        ]);

        $service = Service::findOrFail($data['service_id']);

        // Verify this is a flexible service
        if (!$service->isFlexibleService()) {
            return response()->json(['error' => 'This service is not a flexible service.'], 400);
        }

        // Validate the selections match the required counts
        $validationResult = app(\App\Services\FlexibleServiceAccessService::class)
            ->validateSelections($service, $data['selected_lessons'] ?? [], $data['selected_assessments'] ?? []);

        if (!$validationResult['valid']) {
            return response()->json(['error' => $validationResult['message']], 422);
        }

        $cart = CartSession::current();

        // Check if this service is already in cart
        $existingItem = $cart->items()->where('service_id', $service->id)->first();

        if ($existingItem) {
            // Update existing item's metadata
            $existingItem->metadata = [
                'selected_lessons' => $data['selected_lessons'] ?? [],
                'selected_assessments' => $data['selected_assessments'] ?? [],
            ];
            $existingItem->save();
        } else {
            // Create new cart item with metadata
            $cart->items()->create([
                'service_id' => $service->id,
                'quantity' => 1,
                'price' => $service->price,
                'metadata' => [
                    'selected_lessons' => $data['selected_lessons'] ?? [],
                    'selected_assessments' => $data['selected_assessments'] ?? [],
                ],
            ]);
        }

        return response()->json([
            'success' => true,
            'cart' => $cart->load('items.service', 'items.product')
        ]);
    }

    public function removeFromCart( int $id)
    {
        $cart   = CartSession::current();
        

        // delete only the matching row in this user’s cart
         $cart->items()->where('id', $id)->delete();

        return response()->json(
            $cart->fresh()->load('items.service', 'items.product')
        );
    }

    public function updateQuantity(Request $request, $cartItemId)
{ // Validate the incoming request data
     $data = $request->validate([
            'quantity' => 'required|integer|min:1',
        ]);

        CartItem::whereKey($cartItemId)
                ->update(['quantity' => $data['quantity']]);

        return response()->json(
            CartSession::current()->load('items.service', 'items.product')
        );
}
}
