Hello {{ $application->applicant_name }},

Thank you for submitting your application. To complete the process, please verify your email address by clicking the link below:

Verify Email Address: {{ rtrim($portalBaseUrl ?? config('app.frontend_url'), '/') . '/applications/verify/' . $application->verification_token }}

If you did not submit an application, please ignore this email.

Thank you!

--
{{ $brandName ?? config('app.name') }}
Contact us at: {{ $supportEmail ?? config('mail.from.address') }}
