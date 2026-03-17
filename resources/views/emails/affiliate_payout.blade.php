@extends('emails.layout')

@section('title', 'Payout Processed - ' . ($brandName ?? config('app.name')))

@section('content')
    <p>Hello {{ $affiliate->name }},</p>
    <p>Your payout has been processed. Here are the details:</p>
    <table style="width: 100%; border-collapse: collapse; margin: 20px 0;">
        <tr>
            <td style="padding: 8px 12px; border-bottom: 1px solid #e5e7eb; font-weight: 600; color: #374151;">Amount</td>
            <td style="padding: 8px 12px; border-bottom: 1px solid #e5e7eb;">&pound;{{ number_format($payout->amount, 2) }}</td>
        </tr>
        @if($payout->method)
        <tr>
            <td style="padding: 8px 12px; border-bottom: 1px solid #e5e7eb; font-weight: 600; color: #374151;">Method</td>
            <td style="padding: 8px 12px; border-bottom: 1px solid #e5e7eb;">{{ ucfirst(str_replace('_', ' ', $payout->method)) }}</td>
        </tr>
        @endif
        @if($payout->reference)
        <tr>
            <td style="padding: 8px 12px; border-bottom: 1px solid #e5e7eb; font-weight: 600; color: #374151;">Reference</td>
            <td style="padding: 8px 12px; border-bottom: 1px solid #e5e7eb;">{{ $payout->reference }}</td>
        </tr>
        @endif
        <tr>
            <td style="padding: 8px 12px; border-bottom: 1px solid #e5e7eb; font-weight: 600; color: #374151;">Date</td>
            <td style="padding: 8px 12px; border-bottom: 1px solid #e5e7eb;">{{ $payout->paid_at->format('d M Y') }}</td>
        </tr>
    </table>
    @if($payout->notes)
    <p style="font-size: 13px; color: #6b7280;"><strong>Notes:</strong> {{ $payout->notes }}</p>
    @endif
    <p>Thank you for being a valued affiliate partner.</p>
@endsection
