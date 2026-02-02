<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Subscription Widget Demo</title>
    <!-- Include Stripe.js -->
    <script src="https://js.stripe.com/v3/"></script>
    <!-- Load the specific widget from billings.systems -->
    <script src="https://billings.systems/widgets/SubscriptionWidget.js"></script>
</head>
<body >
    {{-- <h1>Subscription Widget Demo</h1>
    <p>This is a temporary test page for the billings.systems SubscriptionWidget. The widget below is initialized with a test customer ID and API key.</p> --}}
    <!-- Widget Container -->
    <div id="billing-widget" ></div>
    <script>
      // Set global API_BASE_URL for the widget, in case it checks window.API_BASE_URL
      window.API_BASE_URL = "https://billings.systems/api/v1";
      console.log('customer id:', "{{ $customerId ?? '' }}");
      console.log('api key:', "{{ $apiKey ?? '' }}");
      const config = {
        customerId: "{{ $customerId ?? '' }}",
        apiKey: "{{ $apiKey ?? '' }}", // Replace with a real API key for production
        theme: "minimal",
        API_BASE_URL: "https://billings.systems/api/v1",
        borderRadius: "medium",
        showLogo: true,
        collectBillingDetails: true,
        debug: false,
        allowCustomerSelection: true,
        showSubscriptionList: false,
        defaultCurrency: "usd",
        enableTrials: false,
        defaultTrialDays: 14,
        skipToPayment: false,
        plans: [
                    {
                      "id": "1",
                      "name": "Tutor AI Plus ",
                      "description":"Demo Description",
                      "amount": 9000,
                      "currency": "usd",
                      "interval": "month",
                      "features": [
                        "ai_analysis",
                        "enhanced_reports"
                      ]
                    },
                    {
  "id": "2",  // Unique ID in billing.systems
  "name": "Year 5 Access",  // CRITICAL: Must match exactly in Laravel
  "description": "Access to all Year 5 courses and assessments",
  "amount": 3999,  // $39.99/month (in cents)
  "currency": "usd",
  "interval": "month",
  "features": [
    "year_group_courses",
    "year_group_assessments",
    "ai_analysis",
    "enhanced_reports"
  ]
}
                  ],
        logoImage: "https://billings.systems/logo.png"
      };
    </script>
    @verbatim
    <script>
      // Initialize widget when DOM is ready
      document.addEventListener('DOMContentLoaded', function() {
        const container = document.getElementById('billing-widget');
        if (container && typeof SubscriptionWidget !== 'undefined') {
          SubscriptionWidget({ config, container });
        } else {
          console.error('Widget container not found or SubscriptionWidget not loaded');
        }
      });
    </script>
    @endverbatim
    {{-- <hr> --}}
    {{-- <small>
      <strong>Instructions:</strong><br>
      - This page is for testing the SubscriptionWidget from billings.systems.<br>
      - The customerId and apiKey are hardcoded for demo purposes.<br>
      - For production, replace <code>apiKey</code> and <code>customerId</code> with real values.<br>
      - No Stripe keys or environment config needed.<br>
    </small> --}}
</body>
</html>
