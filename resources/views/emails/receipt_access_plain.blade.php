{{ $messageType === 'access_granted' ? 'Access granted' : 'Receipt for your purchase' }}

Hello{{ optional($transaction->user)->name ? ' ' . optional($transaction->user)->name : '' }},

@if($messageType === 'access_granted')
Your recent purchase (Transaction #{{ $transaction->id }}) has been processed and access has been granted to the relevant lessons/assessments.
@else
Thank you for your purchase. This email is a receipt for Transaction #{{ $transaction->id }}.
@endif

Order summary:
@foreach($transaction->items as $item)
- {{ $item->description }} x {{ $item->qty }} @ £{{ number_format($item->unit_price,2) }} = £{{ number_format($item->line_total,2) }}
@endforeach

Total: £{{ number_format($transaction->total,2) }}

View your transaction: {{ route('transactions.show', $transaction->id) }}
Invoice: {{ $transaction->invoice_id ?? 'N/A' }}

@if(optional($transaction->user)->role === \App\Models\User::ROLE_GUEST_PARENT)
Complete your profile to unlock the full parent portal and certificates:
{{ route('authenticate-user') }}
@endif

If you did not make this purchase, contact support immediately at {{ $supportEmail ?? config('mail.from.address') }}.

Regards,
The {{ $brandName ?? config('app.name') }} Team
