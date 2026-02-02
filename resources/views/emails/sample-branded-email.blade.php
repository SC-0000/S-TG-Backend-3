@component('emails.components.branded-layout', ['organization' => $organization])
    <h2 style="color: #333; margin-top: 0;">Welcome to {{ $organization->getSetting('branding.organization_name', config('app.name')) }}!</h2>
    
    <p style="color: #555; line-height: 1.6;">
        Hello {{ $recipientName }},
    </p>
    
    <p style="color: #555; line-height: 1.6;">
        Thank you for joining our platform. We're excited to have you on board!
    </p>
    
    <p style="color: #555; line-height: 1.6;">
        This is a sample email demonstrating the branded email layout. Your organization's branding is automatically applied to all email communications.
    </p>
    
    <div style="text-align: center; margin: 30px 0;">
        <a href="{{ $actionUrl }}" class="email-button">
            Get Started
        </a>
    </div>
    
    <p style="color: #555; line-height: 1.6;">
        If you have any questions, feel free to reach out to our support team.
    </p>
    
    <p style="color: #555; line-height: 1.6; margin-top: 30px;">
        Best regards,<br>
        <strong>{{ $organization->getSetting('branding.organization_name', config('app.name')) }} Team</strong>
    </p>
@endcomponent
