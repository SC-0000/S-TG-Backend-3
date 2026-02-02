<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <style>
        body {
            font-family: Arial, sans-serif;
            background-color: #f5f5f5;
            margin: 0;
            padding: 0;
        }
        .email-wrapper {
            width: 100%;
            background-color: #f5f5f5;
            padding: 20px 0;
        }
        .email-container {
            max-width: 600px;
            margin: 0 auto;
            background: white;
            border-radius: 8px;
            overflow: hidden;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
        }
        .email-header {
            background: {{ $organization->getSetting('email.header_color', '#411183') }};
            color: white;
            padding: 30px;
            text-align: center;
        }
        .email-header img {
            max-width: 200px;
            height: auto;
            margin-bottom: 15px;
        }
        .email-header h1 {
            margin: 0 0 10px 0;
            font-size: 24px;
        }
        .email-header p {
            margin: 0;
            font-size: 14px;
            opacity: 0.9;
        }
        .email-content {
            padding: 40px 30px;
        }
        .email-button {
            background: {{ $organization->getSetting('email.button_color', '#1F6DF2') }};
            color: white !important;
            padding: 12px 30px;
            text-decoration: none;
            border-radius: 6px;
            display: inline-block;
            margin: 20px 0;
            font-weight: bold;
        }
        .email-footer {
            background: #f5f5f5;
            padding: 20px 30px;
            text-align: center;
            font-size: 12px;
            color: #666;
        }
        .footer-section {
            margin: 15px 0;
        }
        .footer-links {
            margin: 15px 0;
        }
        .footer-links a {
            color: #666;
            text-decoration: none;
            margin: 0 10px;
        }
        .contact-info p {
            margin: 5px 0;
            line-height: 1.6;
        }
    </style>
</head>
<body>
    <div class="email-wrapper">
        <div class="email-container">
            {{-- Header --}}
            <div class="email-header">
                @if($organization->getSetting('branding.logo_url'))
                    <img src="{{ asset($organization->getSetting('branding.logo_url')) }}" 
                         alt="{{ $organization->getSetting('branding.organization_name', config('app.name')) }}">
                @endif
                
                <h1>{{ $organization->getSetting('branding.organization_name', config('app.name')) }}</h1>
                
                @if($organization->getSetting('branding.tagline'))
                    <p>{{ $organization->getSetting('branding.tagline') }}</p>
                @endif
            </div>
            
            {{-- Content --}}
            <div class="email-content">
                {{ $slot }}
            </div>
            
            {{-- Footer --}}
            <div class="email-footer">
                <div class="footer-section contact-info">
                    @if($organization->getSetting('contact.email'))
                        <p>📧 {{ $organization->getSetting('contact.email') }}</p>
                    @endif
                    
                    @if($organization->getSetting('contact.phone'))
                        <p>📞 {{ $organization->getSetting('contact.phone') }}</p>
                    @endif
                    
                    @if($organization->getSetting('contact.address.city'))
                        <p>📍 
                            @if($organization->getSetting('contact.address.line1'))
                                {{ $organization->getSetting('contact.address.line1') }}, 
                            @endif
                            {{ $organization->getSetting('contact.address.city') }}
                            @if($organization->getSetting('contact.address.country'))
                                , {{ $organization->getSetting('contact.address.country') }}
                            @endif
                        </p>
                    @endif
                    
                    @if($organization->getSetting('contact.business_hours'))
                        <p>🕐 {{ $organization->getSetting('contact.business_hours') }}</p>
                    @endif
                </div>
                
                @if($organization->getSetting('social_media'))
                    <div class="footer-section footer-links">
                        @if($organization->getSetting('social_media.facebook'))
                            <a href="{{ $organization->getSetting('social_media.facebook') }}">Facebook</a>
                        @endif
                        
                        @if($organization->getSetting('social_media.twitter'))
                            <a href="{{ $organization->getSetting('social_media.twitter') }}">Twitter</a>
                        @endif
                        
                        @if($organization->getSetting('social_media.instagram'))
                            <a href="{{ $organization->getSetting('social_media.instagram') }}">Instagram</a>
                        @endif
                        
                        @if($organization->getSetting('social_media.linkedin'))
                            <a href="{{ $organization->getSetting('social_media.linkedin') }}">LinkedIn</a>
                        @endif
                        
                        @if($organization->getSetting('social_media.youtube'))
                            <a href="{{ $organization->getSetting('social_media.youtube') }}">YouTube</a>
                        @endif
                    </div>
                @endif
                
                <div class="footer-section">
                    <p><strong>{{ $organization->getSetting('email.footer_text', '© ' . date('Y') . '. All rights reserved.') }}</strong></p>
                    
                    @if($organization->getSetting('email.footer_disclaimer'))
                        <p style="font-size: 11px; margin-top: 15px; color: #999;">
                            {{ $organization->getSetting('email.footer_disclaimer') }}
                        </p>
                    @endif
                </div>
            </div>
        </div>
    </div>
</body>
</html>
