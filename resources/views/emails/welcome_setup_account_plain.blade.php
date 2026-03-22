Hello {{ $userName }},

Welcome to {{ $brandName ?? config('app.name') }}! Your application has been approved and your account is ready.

To get started, please set up your password by visiting the link below:

{{ $setupUrl }}

This link is valid for 48 hours and can only be used once. If it expires, you can request a new one via the password reset page.

Once you've set your password, you'll be able to log in and access your account straight away.

If you have any questions, don't hesitate to get in touch.

Thank you!
