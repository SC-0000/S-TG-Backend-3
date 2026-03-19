Hello {{ $userName }},

We received a request to reset your password. Use the link below to choose a new one:

{{ $resetUrl }}

This link expires in {{ $expires }} minutes for your security.

If you didn’t request this, you can safely ignore this email.

Need help? Reach out to us at {{ $supportEmail ?? $contactEmail ?? 'support' }}.
