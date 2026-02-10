Hello {{ $feedback->name }},

Thank you for submitting your feedback. We have received the following message from you:

Message:
{{ $feedback->message }}

We will review your feedback and get back to you as soon as possible. If you have any additional questions, feel free to reach out.

Thank you for your input!

--
{{ $brandName ?? config('app.name') }}
Contact us at: {{ $supportEmail ?? config('mail.from.address') }}
