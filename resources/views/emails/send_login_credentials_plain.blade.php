Hello {{ $userName }},

Your account has been created successfully. You can now log in using the credentials below:

Email: {{ $userEmail }}
Password: {{ $password }}

You can log in using the following link: {{ $loginUrl }}

If you have any questions or need help, feel free to reach out.

Thank you!

--
{{ $brandName ?? config('app.name') }}
Contact us at: {{ $supportEmail ?? config('mail.from.address') }}
