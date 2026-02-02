<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Customer Portal Widget Demo</title>
    <!-- Include Stripe.js -->
    <script src="https://js.stripe.com/v3/"></script>
    <!-- Load the specific widget from billings.systems -->
    <script src="https://billings.systems/widgets/PortalWidget.js"></script>
</head>
<body style="">
    {{-- <h1>Customer Portal Widget Demo</h1>
    <p>This is a temporary test page for the billings.systems PortalWidget. The widget below is initialized with a test customer ID and API key.</p> --}}
    <!-- Widget Container -->
    <div id="billing-widget" style=""></div>
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
        logoImage: "https://billings.systems/logo.png"
      };
    </script>
    @verbatim
    <script>
      // Initialize widget when DOM is ready
      document.addEventListener('DOMContentLoaded', function() {
        const container = document.getElementById('billing-widget');
        if (container && typeof PortalWidget !== 'undefined') {
          PortalWidget({ config, container });
        } else {
          console.error('Widget container not found or PortalWidget not loaded');
        }
      });
    </script>
    @endverbatim
    {{-- <hr>
    <small>
      <strong>Instructions:</strong><br>
      - This page is for testing the PortalWidget from billings.systems.<br>
      - The customerId and apiKey are hardcoded for demo purposes.<br>
      - For production, replace <code>apiKey</code> and <code>customerId</code> with real values.<br>
      - No Stripe keys or environment config needed.<br>
    </small> --}}
</body>
</html>
