Hello {{ $applicantName }},

Thank you for your interest in {{ $brandName ?? config('app.name') }}. After reviewing your application, we are unfortunately unable to approve it at this time.

@if($feedback)
Feedback:
{{ $feedback }}
@endif

If you believe this decision was made in error, or if your circumstances have changed, you are welcome to submit a new application.

Contact us: {{ $contactUrl }}

Thank you for your understanding.
