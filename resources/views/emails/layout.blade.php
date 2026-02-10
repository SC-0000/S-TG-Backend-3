@php
    $organization = $organization ?? null;
    $brandName = $brandName ?? ($organization?->getSetting("branding.organization_name") ?? config("app.name"));
    $brandTagline = $brandTagline ?? $organization?->getSetting("branding.tagline");
    $brandDescription = $brandDescription ?? $organization?->getSetting("branding.description");
    $brandLogoUrl = $brandLogoUrl ?? $organization?->getSetting("branding.logo_url");
    $contactEmail = $contactEmail ?? ($organization?->getSetting("contact.email") ?? config("mail.from.address"));
    $contactWebsite = $contactWebsite ?? ($organization?->getSetting("contact.website") ?? config("app.url"));
    $emailHeaderColor = $emailHeaderColor ?? ($organization?->getSetting("email.header_color") ?? "#2563eb");
    $emailHeaderColorSecondary = $emailHeaderColorSecondary ?? ($organization?->getSetting("email.header_color_secondary") ?? "#1d4ed8");
    $emailButtonColor = $emailButtonColor ?? ($organization?->getSetting("email.button_color") ?? $emailHeaderColor);
    $emailButtonColorSecondary = $emailButtonColorSecondary ?? ($organization?->getSetting("email.button_color_secondary") ?? $emailHeaderColorSecondary);
    $footerText = $footerText ?? ($organization?->getSetting("email.footer_text") ?? ("© " . date("Y") . " " . $brandName . ". All rights reserved."));
    $footerDisclaimer = $footerDisclaimer ?? $organization?->getSetting("email.footer_disclaimer");
@endphp
<!DOCTYPE html>
<html lang="en" xmlns="http://www.w3.org/1999/xhtml" xmlns:o="urn:schemas-microsoft-com:office:office">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <meta http-equiv="X-UA-Compatible" content="IE=edge" />
    <meta name="x-apple-disable-message-reformatting" />
    <meta name="format-detection" content="telephone=no,address=no,email=no,date=no,url=no" />
    <title>@yield('title', $brandName)</title>
    
    <!--[if mso]>
    <noscript>
        <xml>
            <o:OfficeDocumentSettings>
                <o:PixelsPerInch>96</o:PixelsPerInch>
            </o:OfficeDocumentSettings>
        </xml>
    </noscript>
    <![endif]-->
    
    <style>
        /* Reset and Base Styles */
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        html, body {
            margin: 0 !important;
            padding: 0 !important;
            height: 100% !important;
            width: 100% !important;
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, Cantarell, 'Fira Sans', 'Droid Sans', 'Helvetica Neue', Arial, sans-serif !important;
        }
        
        body {
            background-color: #f8fafc;
            color: #1f2937;
            line-height: 1.6;
            -webkit-font-smoothing: antialiased;
            -moz-osx-font-smoothing: grayscale;
            text-rendering: optimizeLegibility;
        }
        
        /* Email client specific resets */
        .ExternalClass {
            width: 100%;
        }
        
        .ExternalClass,
        .ExternalClass p,
        .ExternalClass span,
        .ExternalClass font,
        .ExternalClass td,
        .ExternalClass div {
            line-height: 100%;
        }
        
        #MessageViewBody a {
            color: inherit;
            text-decoration: none;
        }
        
        /* Prevent iOS auto-detection */
        .ios-blue-links a {
            color: inherit !important;
            text-decoration: none !important;
        }
        
        /* Main container */
        .email-wrapper {
            width: 100%;
            margin: 0;
            padding: 0;
            background-color: #f8fafc;
        }
        
        .email-container {
            max-width: 600px;
            margin: 0 auto;
            background-color: #ffffff;
            box-shadow: 0 10px 25px rgba(0, 0, 0, 0.1);
            border-radius: 12px;
            overflow: hidden;
        }
        
        /* Header Styles */
        .email-header {
            background: linear-gradient(135deg, {{ $emailHeaderColor }} 0%, {{ $emailHeaderColorSecondary }} 100%);
            padding: 30px 40px;
            text-align: center;
            position: relative;
        }
        
        .email-header::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: url('data:image/svg+xml,<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100"><defs><pattern id="grain" width="100" height="100" patternUnits="userSpaceOnUse"><circle cx="50" cy="50" r="1" fill="rgba(255,255,255,0.05)"/></pattern></defs><rect width="100" height="100" fill="url(%23grain)"/></svg>');
            opacity: 0.3;
        }
        
        .logo-container {
            position: relative;
            z-index: 2;
            margin-bottom: 10px;
        }
        
        .logo {
            height: 50px;
            width: auto;
            max-width: 200px;
            vertical-align: middle;
        }
        
        .header-title {
            color: #ffffff;
            font-size: 24px;
            font-weight: 600;
            margin: 0;
            position: relative;
            z-index: 2;
        }
        
        .header-subtitle {
            color: rgba(255, 255, 255, 0.9);
            font-size: 14px;
            margin: 5px 0 0 0;
            position: relative;
            z-index: 2;
        }
        
        /* Content Styles */
        .email-content {
            padding: 40px;
            font-size: 16px;
            line-height: 1.7;
            color: #374151;
        }
        
        .email-content h1,
        .email-content h2,
        .email-content h3,
        .email-content h4 {
            color: #1f2937;
            margin: 0 0 20px 0;
            font-weight: 600;
            line-height: 1.3;
        }
        
        .email-content h1 {
            font-size: 28px;
        }
        
        .email-content h2 {
            font-size: 24px;
        }
        
        .email-content h3 {
            font-size: 20px;
        }
        
        .email-content h4 {
            font-size: 18px;
        }
        
        .email-content p {
            margin: 0 0 20px 0;
        }
        
        .email-content ul,
        .email-content ol {
            margin: 0 0 20px 20px;
        }
        
        .email-content li {
            margin: 0 0 8px 0;
        }
        
        /* Button Styles */
        .btn {
            display: inline-block;
            padding: 16px 32px;
            background: linear-gradient(135deg, {{ $emailButtonColor }} 0%, {{ $emailButtonColorSecondary }} 100%);
            color: #ffffff !important;
            text-decoration: none !important;
            border-radius: 8px;
            font-weight: 600;
            font-size: 16px;
            text-align: center;
            transition: all 0.3s ease;
            border: none;
            cursor: pointer;
            box-shadow: 0 4px 12px rgba(37, 99, 235, 0.3);
        }
        
        .btn:hover {
            background: linear-gradient(135deg, #1d4ed8 0%, #1e40af 100%);
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(37, 99, 235, 0.4);
        }
        
        .btn-secondary {
            background: linear-gradient(135deg, #6b7280 0%, #4b5563 100%);
            box-shadow: 0 4px 12px rgba(107, 114, 128, 0.3);
        }
        
        .btn-secondary:hover {
            background: linear-gradient(135deg, #4b5563 0%, #374151 100%);
            box-shadow: 0 6px 20px rgba(107, 114, 128, 0.4);
        }
        
        .btn-success {
            background: linear-gradient(135deg, #059669 0%, #047857 100%);
            box-shadow: 0 4px 12px rgba(5, 150, 105, 0.3);
        }
        
        .btn-success:hover {
            background: linear-gradient(135deg, #047857 0%, #065f46 100%);
            box-shadow: 0 6px 20px rgba(5, 150, 105, 0.4);
        }
        
        /* Card/Panel Styles */
        .card {
            background: #ffffff;
            border: 1px solid #e5e7eb;
            border-radius: 8px;
            padding: 24px;
            margin: 20px 0;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.05);
        }
        
        .card-header {
            background: #f9fafb;
            padding: 20px 24px;
            border-bottom: 1px solid #e5e7eb;
            border-radius: 8px 8px 0 0;
            margin: -24px -24px 20px -24px;
        }
        
        .card-title {
            font-size: 18px;
            font-weight: 600;
            color: #1f2937;
            margin: 0;
        }
        
        .alert {
            padding: 16px 20px;
            border-radius: 8px;
            margin: 20px 0;
            border-left: 4px solid;
        }
        
        .alert-info {
            background: #eff6ff;
            border-left-color: #2563eb;
            color: #1e40af;
        }
        
        .alert-success {
            background: #ecfdf5;
            border-left-color: #059669;
            color: #047857;
        }
        
        .alert-warning {
            background: #fffbeb;
            border-left-color: #d97706;
            color: #92400e;
        }
        
        .alert-error {
            background: #fef2f2;
            border-left-color: #dc2626;
            color: #b91c1c;
        }
        
        /* Footer Styles */
        .email-footer {
            background: #f9fafb;
            padding: 30px 40px;
            border-top: 1px solid #e5e7eb;
            text-align: center;
        }
        
        .footer-content {
            color: #6b7280;
            font-size: 14px;
            line-height: 1.6;
        }
        
        .footer-content p {
            margin: 0 0 12px 0;
        }
        
        .footer-content a {
            color: #2563eb;
            text-decoration: none;
        }
        
        .footer-content a:hover {
            text-decoration: underline;
        }
        
        .footer-brand {
            font-weight: 600;
            color: #1f2937;
            margin-bottom: 8px;
        }
        
        .social-links {
            margin-top: 20px;
        }
        
        .social-links a {
            display: inline-block;
            margin: 0 8px;
            color: #6b7280;
            text-decoration: none;
        }
        
        /* Responsive Design */
        @media only screen and (max-width: 600px) {
            .email-container {
                margin: 0 !important;
                border-radius: 0 !important;
                box-shadow: none !important;
            }
            
            .email-header,
            .email-content,
            .email-footer {
                padding: 25px 20px !important;
            }
            
            .header-title {
                font-size: 20px !important;
            }
            
            .email-content h1 {
                font-size: 24px !important;
            }
            
            .email-content h2 {
                font-size: 20px !important;
            }
            
            .btn {
                display: block !important;
                width: 100% !important;
                padding: 18px 20px !important;
                margin: 10px 0 !important;
            }
            
            .card {
                margin: 15px 0 !important;
                padding: 20px !important;
            }
            
            .card-header {
                margin: -20px -20px 15px -20px !important;
                padding: 15px 20px !important;
            }
        }
        
        @media only screen and (max-width: 480px) {
            .email-header,
            .email-content,
            .email-footer {
                padding: 20px 15px !important;
            }
            
            .email-content {
                font-size: 15px !important;
            }
        }
        
        /* Dark mode support */
        @media (prefers-color-scheme: dark) {
            .card {
                background: #1f2937 !important;
                border-color: #374151 !important;
                color: #e5e7eb !important;
            }
            
            .card-header {
                background: #111827 !important;
                border-color: #374151 !important;
            }
            
            .card-title {
                color: #f9fafb !important;
            }
        }
        
        /* Print styles */
        @media print {
            .email-wrapper {
                background: white !important;
            }
            
            .email-container {
                box-shadow: none !important;
                border: 1px solid #ccc !important;
            }
            
            .btn {
                background: #2563eb !important;
                color: white !important;
            }
        }
    </style>
    
    <!--[if mso]>
    <style type="text/css">
        .email-container {
            width: 600px !important;
        }
        
        .btn {
            border: none !important;
            padding: 16px 32px !important;
            mso-padding-alt: 0;
        }
        
        .btn-inner {
            padding: 16px 32px !important;
        }
    </style>
    <![endif]-->
</head>
<body>
    <div class="email-wrapper">
        <div class="email-container">
            <!-- Header -->
            <div class="email-header">
                <div class="logo-container">
                    @if(!empty($brandLogoUrl))
                        <img src="{{ asset($brandLogoUrl) }}" 
                             alt="{{ $brandName }}" 
                             class="logo"
                             style="display: block; margin: 0 auto;" />
                    @endif
                </div>
                @hasSection('header-title')
                    <h1 class="header-title">@yield('header-title')</h1>
                @else
                    <h1 class="header-title">{{ $brandName }}</h1>
                @endif
                @hasSection('header-subtitle')
                    <p class="header-subtitle">@yield('header-subtitle')</p>
                @else
                    @if(!empty($brandTagline))
                        <p class="header-subtitle">{{ $brandTagline }}</p>
                    @endif
                @endif
            </div>
            
            <!-- Main Content -->
            <div class="email-content">
                @yield('content')
            </div>
            
            <!-- Footer -->
            <div class="email-footer">
                <div class="footer-content">
                    <div class="footer-brand">{{ $brandName }}</div>
                    @if(!empty($brandDescription))
                        <p>{{ $brandDescription }}</p>
                    @endif
                    
                    <hr style="border: none; border-top: 1px solid #e5e7eb; margin: 20px 0;" />
                    
                    <p>
                        <strong>Contact us:</strong><br />
                        @if(!empty($contactEmail))
                            Email: <a href="mailto:{{ $contactEmail }}">{{ $contactEmail }}</a><br />
                        @endif
                        @if(!empty($contactWebsite))
                            Visit our website: <a href="{{ $contactWebsite }}">{{ $contactWebsite }}</a>
                        @endif
                    </p>
                    
                    <p style="margin-top: 20px;">
                        {{ $footerText }}<br />
                        <small style="color: #9ca3af;">
                            This email was sent to you because you have an account with {{ $brandName }}.
                            @hasSection('unsubscribe')
                                <br /><a href="@yield('unsubscribe')" style="color: #9ca3af;">Unsubscribe</a>
                            @endif
                            @if(!empty($footerDisclaimer))
                                <br />{{ $footerDisclaimer }}
                            @endif
                        </small>
                    </p>
                </div>
            </div>
        </div>
    </div>
</body>
</html>
