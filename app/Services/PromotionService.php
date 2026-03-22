<?php

namespace App\Services;

use App\Models\Promotion;
use App\Models\PromotionUsage;
use App\Models\Transaction;
use Illuminate\Support\Facades\DB;

class PromotionService
{
    /**
     * Validate a promotion code against the current cart context.
     *
     * @param  string      $code
     * @param  float       $subtotal
     * @param  int         $userId
     * @param  int|null    $orgId
     * @param  array       $cartItems  [{type: 'service'|'product', id: int, line_total: float}, ...]
     * @return array{valid: bool, promotion: ?Promotion, error: ?string}
     */
    public function validateCode(string $code, float $subtotal, int $userId, ?int $orgId, array $cartItems = []): array
    {
        $promotion = Promotion::where('code', $code)
            ->forOrg($orgId)
            ->first();

        if (!$promotion) {
            return ['valid' => false, 'promotion' => null, 'error' => 'Invalid promotion code.'];
        }

        if (!$promotion->is_active) {
            return ['valid' => false, 'promotion' => null, 'error' => 'This promotion is no longer active.'];
        }

        if ($promotion->starts_at && $promotion->starts_at->isFuture()) {
            return ['valid' => false, 'promotion' => null, 'error' => 'This promotion has not started yet.'];
        }

        if ($promotion->ends_at && $promotion->ends_at->isPast()) {
            return ['valid' => false, 'promotion' => null, 'error' => 'This promotion has expired.'];
        }

        if (!$promotion->hasUsagesRemaining()) {
            return ['valid' => false, 'promotion' => null, 'error' => 'This promotion has reached its usage limit.'];
        }

        if (!$promotion->hasUserUsagesRemaining($userId)) {
            return ['valid' => false, 'promotion' => null, 'error' => 'You have already used this promotion the maximum number of times.'];
        }

        if ($promotion->min_purchase_amount && $subtotal < $promotion->min_purchase_amount) {
            return [
                'valid' => false,
                'promotion' => null,
                'error' => 'Minimum purchase of £' . number_format($promotion->min_purchase_amount, 2) . ' required.',
            ];
        }

        // Check applicability to cart items
        if ($promotion->applicable_to !== Promotion::APPLY_ALL && !empty($cartItems)) {
            $hasApplicable = false;
            foreach ($cartItems as $item) {
                if ($promotion->isApplicableTo($item['type'], $item['id'])) {
                    $hasApplicable = true;
                    break;
                }
            }
            if (!$hasApplicable) {
                return ['valid' => false, 'promotion' => null, 'error' => 'This promotion does not apply to any items in your cart.'];
            }
        }

        return ['valid' => true, 'promotion' => $promotion, 'error' => null];
    }

    /**
     * Calculate the discount amount for a promotion.
     */
    public function calculateDiscount(Promotion $promotion, float $subtotal, array $cartItems = []): float
    {
        $applicableSubtotal = $this->getApplicableSubtotal($promotion, $subtotal, $cartItems);

        if ($applicableSubtotal <= 0) return 0;

        if ($promotion->discount_type === Promotion::DISCOUNT_PERCENTAGE) {
            $discount = $applicableSubtotal * ($promotion->discount_value / 100);
            if ($promotion->max_discount_amount) {
                $discount = min($discount, $promotion->max_discount_amount);
            }
        } else {
            $discount = min($promotion->discount_value, $applicableSubtotal);
        }

        return round(max(0, $discount), 2);
    }

    /**
     * Record promotion usage after a successful transaction.
     */
    public function applyPromotion(Promotion $promotion, Transaction $transaction, int $userId, float $discountApplied): void
    {
        DB::transaction(function () use ($promotion, $transaction, $userId, $discountApplied) {
            // Atomic increment to prevent race conditions
            DB::table('promotions')
                ->where('id', $promotion->id)
                ->increment('used_count');

            PromotionUsage::create([
                'promotion_id'    => $promotion->id,
                'user_id'         => $userId,
                'transaction_id'  => $transaction->id,
                'discount_applied' => $discountApplied,
            ]);
        });
    }

    /**
     * Find the best auto-discount that applies to this cart.
     */
    public function getAutoDiscounts(float $subtotal, ?int $orgId, array $cartItems = []): ?Promotion
    {
        $autoPromos = Promotion::autoDiscounts()
            ->active()
            ->forOrg($orgId)
            ->get();

        $bestPromo = null;
        $bestDiscount = 0;

        foreach ($autoPromos as $promo) {
            if ($promo->min_purchase_amount && $subtotal < $promo->min_purchase_amount) {
                continue;
            }

            $discount = $this->calculateDiscount($promo, $subtotal, $cartItems);
            if ($discount > $bestDiscount) {
                $bestDiscount = $discount;
                $bestPromo = $promo;
            }
        }

        return $bestPromo;
    }

    /**
     * Calculate the subtotal of items the promotion applies to.
     */
    private function getApplicableSubtotal(Promotion $promotion, float $subtotal, array $cartItems): float
    {
        if ($promotion->applicable_to === Promotion::APPLY_ALL || empty($cartItems)) {
            return $subtotal;
        }

        $applicable = 0;
        foreach ($cartItems as $item) {
            if ($promotion->isApplicableTo($item['type'] ?? '', $item['id'] ?? 0)) {
                $applicable += $item['line_total'] ?? 0;
            }
        }

        return $applicable;
    }
}
