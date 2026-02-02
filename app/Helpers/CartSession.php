<?php
// app/Helpers/CartSession.php  (create this file)
namespace App\Helpers;

use App\Models\Cart;

class CartSession
{
    /** Always return the current Cart model */
    public static function current(): Cart
    {
        $sessionId = session()->get('session_id');

        if (!$sessionId) {
            $sessionId = uniqid('guest_', true);
            session(['session_id' => $sessionId]);
        }

        return Cart::firstOrCreate(['session_id' => $sessionId]);
    }
}
