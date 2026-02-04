<?php
// app/Helpers/CartSession.php  (create this file)
namespace App\Helpers;

use App\Models\Cart;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class CartSession
{
    /** Always return the current Cart model */
    public static function current(): Cart
    {
        $request = app()->bound('request') ? request() : null;
        $user = auth('sanctum')->user() ?? auth()->user();
        $cartToken = self::extractCartToken($request);

        if ($user) {
            $cart = Cart::firstOrCreate(['user_id' => $user->id], ['session_id' => null]);

            if ($cartToken) {
                self::mergeGuestCart($cart, Cart::where('cart_token', $cartToken)->first());
            }

            $sessionId = self::getSessionId($request);
            if ($sessionId) {
                self::mergeGuestCart($cart, Cart::where('session_id', $sessionId)->first());
            }

            return $cart;
        }

        if ($cartToken) {
            $cart = Cart::firstOrCreate(
                ['cart_token' => $cartToken],
                ['session_id' => self::ensureSessionId($request)]
            );

            return self::ensureCartToken($cart);
        }

        $sessionId = self::ensureSessionId($request);
        if ($sessionId) {
            $cart = Cart::firstOrCreate(
                ['session_id' => $sessionId],
                ['cart_token' => self::generateCartToken()]
            );

            return self::ensureCartToken($cart);
        }

        return Cart::create(['cart_token' => self::generateCartToken()]);
    }

    private static function extractCartToken(?Request $request): ?string
    {
        if (!$request) {
            return null;
        }

        $token = $request->header('X-Cart-Token') ?: $request->input('cart_token');
        if (is_string($token)) {
            $token = trim($token);
        }

        return $token ?: null;
    }

    private static function getSessionId(?Request $request): ?string
    {
        if (!$request || !method_exists($request, 'hasSession') || !$request->hasSession()) {
            return null;
        }

        $sessionId = $request->session()->get('session_id');

        return $sessionId ?: null;
    }

    private static function ensureSessionId(?Request $request): ?string
    {
        if (!$request || !method_exists($request, 'hasSession') || !$request->hasSession()) {
            return null;
        }

        $sessionId = $request->session()->get('session_id');
        if (!$sessionId) {
            $sessionId = 'guest_' . Str::uuid()->toString();
            $request->session()->put('session_id', $sessionId);
        }

        return $sessionId;
    }

    private static function generateCartToken(): string
    {
        return Str::uuid()->toString();
    }

    private static function ensureCartToken(Cart $cart): Cart
    {
        if (!$cart->cart_token) {
            $cart->cart_token = self::generateCartToken();
            $cart->save();
        }

        return $cart;
    }

    private static function mergeGuestCart(Cart $cart, ?Cart $guestCart): void
    {
        if (!$guestCart || $guestCart->id === $cart->id) {
            return;
        }

        $guestItems = $guestCart->items()->get();
        $existingItems = $cart->items()->get();

        foreach ($guestItems as $item) {
            $match = $existingItems->first(function ($existing) use ($item) {
                return (int) $existing->service_id === (int) $item->service_id
                    && (int) $existing->product_id === (int) $item->product_id
                    && ($existing->metadata ?? null) === ($item->metadata ?? null);
            });

            if ($match) {
                $match->quantity += $item->quantity;
                $match->save();
                $item->delete();
            } else {
                $item->cart_id = $cart->id;
                $item->save();
                $existingItems->push($item);
            }
        }

        $guestCart->delete();
    }
}
