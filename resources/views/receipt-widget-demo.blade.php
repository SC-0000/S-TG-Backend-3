{{-- <!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Receipt Widget Demo</title>
    <!-- Include Stripe.js -->
    <script src="https://js.stripe.com/v3/"></script>
    <!-- Load the specific widget from billings.systems -->
    <script src="https://billings.systems/widgets/ReceiptWidget.js"></script>
</head>
<body>
    <!-- Widget Container -->
    <div id="billing-widget"></div>
    <script>
      // Set global API_BASE_URL for the widget, in case it checks window.API_BASE_URL
      window.API_BASE_URL = "https://billings.systems/api/v1";
      console.log('customer id:', "{{ $customerId ?? '' }}");
      console.log('api key:', "{{ $apiKey ?? '' }}");
      console.log('invoice id:', "{{ $invoiceId ?? '' }}");
      const config = {
        customerId: "{{ $customerId ?? '' }}",
        apiKey: "{{ $apiKey ?? '' }}", // Replace with a real API key for production
        invoiceId: "{{ $invoiceId ?? '' }}",
        theme: "dark",
        borderRadius: "medium",
        showLogo: true,
        collectBillingDetails: true,
        debug: true
      };
    </script>
    @verbatim
    <script>
      // Initialize widget when DOM is ready
      document.addEventListener('DOMContentLoaded', function() {
        const container = document.getElementById('billing-widget');
        if (container && typeof ReceiptWidget !== 'undefined') {
          ReceiptWidget({ config, container });
        } else {
          console.error('Widget container not found or ReceiptWidget not loaded');
        }
      });
    </script>
    @endverbatim
    <!--
      ✨ SIMPLIFIED SETUP INSTRUCTIONS:
      1. Replace 'YOUR_API_KEY' with your actual API key from billings.systems
      2. Replace 'YOUR_CUSTOMER_ID' with the actual customer ID (for setup/pay/portal modes)
      3. Customize the config object as needed for your use case

      ✅ NO LONGER NEEDED:
      - Stripe publishable keys (automatically managed)
      - API base URLs (automatically detected)
      - Environment configuration (handled by billings.systems)

      🔒 ENHANCED SECURITY:
      - Centralized key management
      - Automatic environment detection
      - Secure key rotation without code changes
    -->
</body>
</html> --}}
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Receipt Widget Demo</title>
    <!-- Include Stripe.js -->
    <script src="https://js.stripe.com/v3/"></script>
    <!-- Load the specific widget from billings.systems -->
    <script src="https://billings.systems/widgets/ReceiptWidget.js"></script>
</head>
<body >
    {{-- <h1>Billing Widget Demo</h1>
    <p>This is a temporary test page for the billings.systems SetupWidget. The widget below is initialized with a test customer ID and API key.</p> --}}
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
        invoiceId: "{{ $invoiceId ?? '' }}", // Add this line to pass the invoice ID
        API_BASE_URL: "https://billings.systems/api/v1",
        theme: "minimal",
       
        borderRadius: "medium",
        showLogo: true,
        collectBillingDetails: true,
        debug: true
      };
    </script>
    @verbatim
    <script>
      // Initialize widget when DOM is ready
      document.addEventListener('DOMContentLoaded', function() {
        const container = document.getElementById('billing-widget');
        if (container && typeof ReceiptWidget !== 'undefined') {
          ReceiptWidget({ config, container });
        } else {
          console.error('Widget container not found or SetupWidget not loaded');
        }
      });
    </script>
    @endverbatim
    <hr>
    {{-- <small>
      <strong>Instructions:</strong><br>
      - This page is for testing the SetupWidget from billings.systems.<br>
      - The customerId and apiKey are hardcoded for demo purposes.<br>
      - For production, replace <code>apiKey</code> and <code>customerId</code> with real values.<br>
      - No Stripe keys or environment config needed.<br>
    </small> --}}
</body>
</html>
