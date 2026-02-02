Hello {{ $application->applicant_name }},

Thank you for submitting your application. To complete the process, please verify your email address by clicking the link below:

Verify Email Address: {{ route('application.verify', ['token' => $application->verification_token]) }}

If you did not submit an application, please ignore this email.

Thank you!

--
Eleven Plus Tutor
Contact us at: ept@pa.team
