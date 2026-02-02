<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Pay Invoice Widget Demo</title>
    <!-- Include Stripe.js -->
    <script src="https://js.stripe.com/v3/"></script>
    <!-- Load the specific widget from billings.systems -->
    <script src="https://billings.systems/widgets/PaymentWidget.js"></script>
</head>
<body >
    {{-- <h1>Pay Invoice Widget Demo</h1>
    <p>This is a temporary test page for the billings.systems PaymentWidget. The widget below is initialized with a test customer ID and API key.</p> --}}
    <!-- Widget Container -->
    <div id="billing-widget" ></div>
    <script>
      // Set global API_BASE_URL for the widget, in case it checks window.API_BASE_URL
      window.API_BASE_URL = "https://billings.systems/api/v1";
      console.log('customer id:', "{{ $customerId ?? '' }}");
      console.log('api key:', "{{ $apiKey ?? '' }}");
      console.log('invoice id:', "{{ $invoiceId ?? '' }}");
      const config = {
        customerId: "{{ $customerId ?? '' }}",
        invoiceId: "{{ $invoiceId ?? '' }}",
        apiKey: "{{ $apiKey ?? '' }}", // Replace with a real API key for production
        API_BASE_URL: "https://billings.systems/api/v1",
        theme: "minimal",
        borderRadius: "medium",
        showLogo: true,
        collectBillingDetails: true,
        debug: false,
        amount: 2500,
        currency: "usd",
        logoImage: "https://billings.systems/logo.png"
      };
    </script>
    @verbatim
    <script>
      // Initialize widget when DOM is ready
      document.addEventListener('DOMContentLoaded', function() {
        const container = document.getElementById('billing-widget');
        if (container && typeof PaymentWidget !== 'undefined') {
          PaymentWidget({ config, container });
        } else {
          console.error('Widget container not found or PaymentWidget not loaded');
        }
      });
    </script>
    @endverbatim
    {{-- <hr>
    <small>
      <strong>Instructions:</strong><br>
      - This page is for testing the PaymentWidget from billings.systems.<br>
      - The customerId and apiKey are hardcoded for demo purposes.<br>
      - For production, replace <code>apiKey</code> and <code>customerId</code> with real values.<br>
      - No Stripe keys or environment config needed.<br>
    </small> --}}
</body>
</html>
