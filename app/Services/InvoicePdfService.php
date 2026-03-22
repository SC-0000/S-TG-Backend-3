<?php

namespace App\Services;

use App\Models\Organization;
use App\Models\OrganizationInvoice;
use App\Models\Transaction;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\Storage;

class InvoicePdfService
{
    /**
     * Generate a branded PDF invoice for a customer transaction.
     *
     * @return string The storage path of the generated PDF
     */
    public function generateForTransaction(Transaction $transaction): string
    {
        $transaction->load(['items', 'user', 'invoice']);

        // Resolve organization for branding
        $org = null;
        if ($transaction->organization_id) {
            $org = Organization::find($transaction->organization_id);
        }
        if (! $org && $transaction->user?->current_organization_id) {
            $org = Organization::find($transaction->user->current_organization_id);
        }

        $branding = $this->getOrgBranding($org);

        $items = $transaction->items->map(fn ($item) => [
            'description' => $item->description,
            'quantity'    => $item->qty,
            'unit_price'  => $item->unit_price,
            'total'       => $item->line_total,
        ])->all();

        $data = [
            'branding'       => $branding,
            'invoice_number' => $transaction->invoice?->invoice_number ?? "TXN-{$transaction->id}",
            'date'           => $transaction->created_at?->format('d M Y'),
            'due_date'       => $transaction->invoice?->due_date?->format('d M Y'),
            'paid_at'        => $transaction->paid_at?->format('d M Y'),
            'customer_name'  => $transaction->user?->name ?? 'Customer',
            'customer_email' => $transaction->user_email ?? $transaction->user?->email ?? '',
            'items'          => $items,
            'subtotal'       => $transaction->subtotal,
            'tax'            => $transaction->tax,
            'discount'       => $transaction->discount,
            'total'          => $transaction->total,
            'status'         => $transaction->status,
            'currency'       => '£',
        ];

        $pdf = Pdf::loadView('pdf.invoice', $data);
        $pdf->setPaper('a4');

        $path = "invoices/transactions/{$transaction->id}.pdf";
        Storage::put($path, $pdf->output());

        // Update invoice pdf_url if invoice exists
        if ($transaction->invoice) {
            $transaction->invoice->update(['pdf_url' => Storage::url($path)]);
        }

        return $path;
    }

    /**
     * Generate a branded PDF invoice for an organization invoice (admin → org).
     *
     * @return string The storage path of the generated PDF
     */
    public function generateForOrganization(OrganizationInvoice $invoice): string
    {
        $invoice->load('organization');

        // Use platform branding (or org branding for self-invoices)
        $branding = [
            'name'        => config('app.name', 'Platform'),
            'logo_url'    => null,
            'tagline'     => null,
            'primary'     => '#4F46E5',
            'accent'      => '#7C3AED',
            'email'       => config('mail.from.address'),
            'phone'       => null,
            'address'     => null,
        ];

        $items = collect($invoice->line_items ?? [])->map(fn ($item) => [
            'description' => $item['label'] ?? $item['description'] ?? '',
            'quantity'    => $item['quantity'] ?? 1,
            'unit_price'  => $item['unit_price'] ?? 0,
            'total'       => $item['total'] ?? 0,
        ])->all();

        $data = [
            'branding'          => $branding,
            'invoice_number'    => $invoice->invoice_number,
            'date'              => $invoice->created_at?->format('d M Y'),
            'period_start'      => $invoice->period_start?->format('d M Y'),
            'period_end'        => $invoice->period_end?->format('d M Y'),
            'paid_at'           => $invoice->paid_at?->format('d M Y'),
            'organization_name' => $invoice->organization?->name ?? 'Organization',
            'items'             => $items,
            'subtotal'          => $invoice->subtotal,
            'tax'               => $invoice->tax,
            'total'             => $invoice->total,
            'status'            => $invoice->status,
            'currency'          => '£',
        ];

        $pdf = Pdf::loadView('pdf.organization-invoice', $data);
        $pdf->setPaper('a4');

        $path = "invoices/organizations/{$invoice->id}.pdf";
        Storage::put($path, $pdf->output());

        // Store URL in metadata
        $metadata = $invoice->metadata ?? [];
        $metadata['pdf_url'] = Storage::url($path);
        $invoice->update(['metadata' => $metadata]);

        return $path;
    }

    /**
     * Get branding data from an organization's settings.
     */
    private function getOrgBranding(?Organization $org): array
    {
        if (! $org) {
            return [
                'name'    => config('app.name', 'Platform'),
                'logo_url' => null,
                'tagline' => null,
                'primary' => '#4F46E5',
                'accent'  => '#7C3AED',
                'email'   => config('mail.from.address'),
                'phone'   => null,
                'address' => null,
            ];
        }

        return [
            'name'    => $org->getSetting('branding.organization_name', $org->name),
            'logo_url' => $org->getSetting('branding.logo_url'),
            'tagline' => $org->getSetting('branding.tagline'),
            'primary' => $org->getSetting('theme.colors.primary', '#4F46E5'),
            'accent'  => $org->getSetting('theme.colors.accent', '#7C3AED'),
            'email'   => $org->getSetting('contact.email'),
            'phone'   => $org->getSetting('contact.phone'),
            'address' => trim(implode(', ', array_filter([
                $org->getSetting('contact.address.line1'),
                $org->getSetting('contact.address.city'),
                $org->getSetting('contact.address.postal_code'),
                $org->getSetting('contact.address.country'),
            ]))),
        ];
    }
}
