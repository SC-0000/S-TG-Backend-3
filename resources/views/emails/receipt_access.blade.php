@extends('emails.layout')

@section('title', $messageType === 'access_granted' ? 'Access Granted' : 'Purchase Receipt')

@section('header-title')
    @if($messageType === 'access_granted')
        🎉 Access Granted!
    @else
        🧾 Purchase Receipt
    @endif
@endsection

@section('header-subtitle')
    @if($messageType === 'access_granted')
        Your content is now available
    @else
        Thank you for your purchase
    @endif
@endsection

@section('content')
    <h1>
        @if($messageType === 'access_granted')
            Welcome to Your Learning Journey!
        @else
            Purchase Confirmation
        @endif
    </h1>
    
    <p>Hello{{ optional($transaction->user)->name ? ', <strong>' . optional($transaction->user)->name . '</strong>' : '' }},</p>
    
    @if($messageType === 'access_granted')
        @component('emails.components.alert', ['type' => 'success'])
            <strong>Great news!</strong> Your recent purchase (Transaction #{{ $transaction->id }}) has been processed successfully, and access has been granted to all your purchased content.
        @endcomponent
        
        <p>You can now access your lessons and assessments immediately. Start your learning journey today!</p>
    @else
        <p>Thank you for choosing Eleven Plus Tutor! This email serves as your receipt for Transaction #{{ $transaction->id }}.</p>
    @endif
    
    @component('emails.components.card', ['title' => '📋 Order Summary'])
        <table style="width: 100%; border-collapse: collapse; margin: 0;">
            <thead>
                <tr style="background-color: #f8fafc;">
                    <th style="text-align: left; padding: 12px; border-bottom: 2px solid #e5e7eb; font-weight: 600; color: #374151;">Item</th>
                    <th style="text-align: center; padding: 12px; border-bottom: 2px solid #e5e7eb; font-weight: 600; color: #374151; width: 60px;">Qty</th>
                    <th style="text-align: right; padding: 12px; border-bottom: 2px solid #e5e7eb; font-weight: 600; color: #374151; width: 100px;">Unit Price</th>
                    <th style="text-align: right; padding: 12px; border-bottom: 2px solid #e5e7eb; font-weight: 600; color: #374151; width: 100px;">Total</th>
                </tr>
            </thead>
            <tbody>
                @foreach($transaction->items as $item)
                    <tr>
                        <td style="padding: 12px; border-bottom: 1px solid #f3f4f6; color: #1f2937;">{{ $item->description }}</td>
                        <td style="padding: 12px; border-bottom: 1px solid #f3f4f6; text-align: center; color: #6b7280;">{{ $item->qty }}</td>
                        <td style="padding: 12px; border-bottom: 1px solid #f3f4f6; text-align: right; color: #6b7280;">£{{ number_format($item->unit_price, 2) }}</td>
                        <td style="padding: 12px; border-bottom: 1px solid #f3f4f6; text-align: right; font-weight: 600; color: #1f2937;">£{{ number_format($item->line_total, 2) }}</td>
                    </tr>
                @endforeach
            </tbody>
            <tfoot>
                <tr style="background-color: #f8fafc;">
                    <td colspan="3" style="padding: 16px 12px; text-align: right; font-weight: 700; color: #1f2937; border-top: 2px solid #e5e7eb;">Total Amount:</td>
                    <td style="padding: 16px 12px; text-align: right; font-weight: 700; color: #2563eb; font-size: 18px; border-top: 2px solid #e5e7eb;">£{{ number_format($transaction->total, 2) }}</td>
                </tr>
            </tfoot>
        </table>
        
        @component('emails.components.spacer', ['height' => '20px'])
        @endcomponent
        
        <div style="text-align: center;">
            @component('emails.components.button', ['href' => route('transactions.show', $transaction->id), 'variant' => 'primary'])
                View Full Receipt
            @endcomponent
        </div>
        
        <p style="color: #6b7280; font-size: 14px; margin-top: 15px;">
            <strong>Invoice ID:</strong> {{ $transaction->invoice_id ?? 'N/A' }} • 
            <strong>Transaction:</strong> #{{ $transaction->id }}
        </p>
    @endcomponent
    
    @if(optional($transaction->user)->role === \App\Models\User::ROLE_GUEST_PARENT)
        @component('emails.components.alert', ['type' => 'info'])
            <strong>🚀 Unlock More Features!</strong><br>
            You currently have a temporary account. Complete your profile to unlock the full parent portal, certificates, and saved payment methods for future purchases.
        @endcomponent
        
        <div style="text-align: center; margin: 25px 0;">
            @component('emails.components.button', ['href' => route('authenticate-user'), 'variant' => 'secondary'])
                Complete Your Profile
            @endcomponent
        </div>
    @endif
    
    @if($messageType === 'access_granted')
        @component('emails.components.card', ['title' => '🎯 Getting Started'])
            <h4 style="margin: 0 0 15px 0; color: #2563eb;">Your Next Steps:</h4>
            <ol style="margin: 0; padding-left: 20px; line-height: 1.8;">
                <li><strong>Access Your Content:</strong> Log in to your account to view purchased materials</li>
                <li><strong>Start Learning:</strong> Begin with any lesson or assessment</li>
                <li><strong>Track Progress:</strong> Monitor your improvement through our analytics</li>
                <li><strong>Get Support:</strong> Reach out if you need any assistance</li>
            </ol>
        @endcomponent
    @endif
    
    @component('emails.components.divider')
    @endcomponent
    
    @component('emails.components.alert', ['type' => 'warning'])
        <strong>Security Notice:</strong> If you did not make this purchase, please contact our support team immediately at <a href="mailto:ept@pa.team" style="color: #d97706;">ept@pa.team</a>
    @endcomponent
    
    <p style="text-align: center; margin-top: 30px;">
        Thank you for choosing <strong>Eleven Plus Tutor</strong> for your educational journey!
    </p>
@endsection
