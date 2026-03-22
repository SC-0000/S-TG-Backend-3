<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrganizationInvoice extends Model
{
    protected $fillable = [
        'organization_id',
        'invoice_number',
        'period_start',
        'period_end',
        'line_items',
        'subtotal',
        'tax',
        'total',
        'status',
        'paid_at',
        'billing_invoice_id',
        'metadata',
    ];

    protected $casts = [
        'line_items' => 'array',
        'metadata' => 'array',
        'period_start' => 'date',
        'period_end' => 'date',
        'paid_at' => 'datetime',
        'subtotal' => 'decimal:2',
        'tax' => 'decimal:2',
        'total' => 'decimal:2',
    ];

    protected static function booted()
    {
        static::creating(function (OrganizationInvoice $invoice) {
            if (!$invoice->invoice_number) {
                $yearMonth = now()->format('Ym');
                $orgId = $invoice->organization_id;

                $sequence = static::where('organization_id', $orgId)
                    ->where('invoice_number', 'like', "INV-{$orgId}-{$yearMonth}-%")
                    ->count() + 1;

                $invoice->invoice_number = sprintf('INV-%d-%s-%04d', $orgId, $yearMonth, $sequence);
            }
        });
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function scopeForStatus($query, string $status)
    {
        return $query->where('status', $status);
    }

    public function scopeUnpaid($query)
    {
        return $query->whereIn('status', ['draft', 'pending', 'overdue']);
    }

    public function isPaid(): bool
    {
        return $this->status === 'paid';
    }

    public function markAsPaid(): void
    {
        $this->update([
            'status' => 'paid',
            'paid_at' => now(),
        ]);
    }
}
