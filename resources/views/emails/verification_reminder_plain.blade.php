Hello {{ $applicantName }},

We noticed that you submitted an application to {{ $brandName ?? config('app.name') }} but haven't verified your email address yet.

Please visit the link below to verify your email and complete your application:

{{ $verifyUrl }}

Your application cannot be processed until your email is verified.

If you did not submit an application, please ignore this email.

Thank you!
