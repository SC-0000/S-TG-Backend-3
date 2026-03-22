<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Invoice {{ $invoice_number }}</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Helvetica Neue', Arial, sans-serif; font-size: 13px; color: #1f2937; line-height: 1.5; }
        .container { padding: 40px; }
        .header { display: table; width: 100%; margin-bottom: 40px; }
        .header-left { display: table-cell; width: 60%; vertical-align: top; }
        .header-right { display: table-cell; width: 40%; vertical-align: top; text-align: right; }
        .logo { max-height: 60px; max-width: 200px; margin-bottom: 8px; }
        .company-name { font-size: 22px; font-weight: 700; color: {{ $branding['primary'] }}; margin-bottom: 4px; }
        .tagline { font-size: 11px; color: #6b7280; }
        .invoice-title { font-size: 28px; font-weight: 700; color: {{ $branding['primary'] }}; margin-bottom: 4px; }
        .invoice-number { font-size: 14px; color: #6b7280; }
        .meta-row { display: table; width: 100%; margin-bottom: 30px; }
        .meta-col { display: table-cell; width: 50%; vertical-align: top; }
        .meta-label { font-size: 10px; text-transform: uppercase; color: #9ca3af; font-weight: 600; letter-spacing: 0.5px; margin-bottom: 4px; }
        .meta-value { font-size: 13px; color: #374151; }
        table.items { width: 100%; border-collapse: collapse; margin-bottom: 24px; }
        table.items thead th { background: {{ $branding['primary'] }}; color: #fff; font-size: 11px; text-transform: uppercase; letter-spacing: 0.5px; padding: 10px 12px; text-align: left; }
        table.items thead th:last-child { text-align: right; }
        table.items thead th:nth-child(2), table.items thead th:nth-child(3) { text-align: center; }
        table.items tbody td { padding: 10px 12px; border-bottom: 1px solid #e5e7eb; }
        table.items tbody td:last-child { text-align: right; }
        table.items tbody td:nth-child(2), table.items tbody td:nth-child(3) { text-align: center; }
        table.items tbody tr:last-child td { border-bottom: 2px solid {{ $branding['primary'] }}; }
        .totals { width: 280px; margin-left: auto; }
        .totals-row { display: table; width: 100%; margin-bottom: 6px; }
        .totals-label { display: table-cell; text-align: right; padding-right: 16px; color: #6b7280; }
        .totals-value { display: table-cell; text-align: right; font-weight: 500; }
        .totals-total { font-size: 18px; font-weight: 700; color: {{ $branding['primary'] }}; border-top: 2px solid {{ $branding['primary'] }}; padding-top: 8px; margin-top: 8px; }
        .status-badge { display: inline-block; padding: 4px 12px; border-radius: 12px; font-size: 11px; font-weight: 600; text-transform: uppercase; }
        .status-paid { background: #d1fae5; color: #065f46; }
        .status-pending { background: #fef3c7; color: #92400e; }
        .status-failed { background: #fee2e2; color: #991b1b; }
        .footer { margin-top: 40px; padding-top: 20px; border-top: 1px solid #e5e7eb; font-size: 11px; color: #9ca3af; text-align: center; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <div class="header-left">
                @if($branding['logo_url'])
                    <img src="{{ public_path(ltrim($branding['logo_url'], '/')) }}" class="logo" alt="">
                @endif
                <div class="company-name">{{ $branding['name'] }}</div>
                @if($branding['tagline'])
                    <div class="tagline">{{ $branding['tagline'] }}</div>
                @endif
            </div>
            <div class="header-right">
                <div class="invoice-title">INVOICE</div>
                <div class="invoice-number">{{ $invoice_number }}</div>
            </div>
        </div>

        <div class="meta-row">
            <div class="meta-col">
                <div class="meta-label">Bill To</div>
                <div class="meta-value">
                    <strong>{{ $customer_name }}</strong><br>
                    {{ $customer_email }}
                </div>
            </div>
            <div class="meta-col" style="text-align: right;">
                <div class="meta-label">Invoice Date</div>
                <div class="meta-value">{{ $date }}</div>
                @if($due_date)
                    <div class="meta-label" style="margin-top: 8px;">Due Date</div>
                    <div class="meta-value">{{ $due_date }}</div>
                @endif
                @if($paid_at)
                    <div class="meta-label" style="margin-top: 8px;">Paid</div>
                    <div class="meta-value">{{ $paid_at }}</div>
                @endif
                <div style="margin-top: 8px;">
                    <span class="status-badge status-{{ $status }}">{{ ucfirst($status) }}</span>
                </div>
            </div>
        </div>

        <table class="items">
            <thead>
                <tr>
                    <th>Description</th>
                    <th>Qty</th>
                    <th>Unit Price</th>
                    <th>Total</th>
                </tr>
            </thead>
            <tbody>
                @foreach($items as $item)
                <tr>
                    <td>{{ $item['description'] }}</td>
                    <td>{{ $item['quantity'] }}</td>
                    <td>{{ $currency }}{{ number_format($item['unit_price'], 2) }}</td>
                    <td>{{ $currency }}{{ number_format($item['total'], 2) }}</td>
                </tr>
                @endforeach
            </tbody>
        </table>

        <div class="totals">
            <div class="totals-row">
                <div class="totals-label">Subtotal</div>
                <div class="totals-value">{{ $currency }}{{ number_format($subtotal, 2) }}</div>
            </div>
            @if($discount > 0)
            <div class="totals-row">
                <div class="totals-label">Discount</div>
                <div class="totals-value">-{{ $currency }}{{ number_format($discount, 2) }}</div>
            </div>
            @endif
            @if($tax > 0)
            <div class="totals-row">
                <div class="totals-label">Tax</div>
                <div class="totals-value">{{ $currency }}{{ number_format($tax, 2) }}</div>
            </div>
            @endif
            <div class="totals-row totals-total">
                <div class="totals-label">Total</div>
                <div class="totals-value">{{ $currency }}{{ number_format($total, 2) }}</div>
            </div>
        </div>

        <div class="footer">
            @if($branding['address'])
                {{ $branding['address'] }}
            @endif
            @if($branding['email'])
                &nbsp;&bull;&nbsp; {{ $branding['email'] }}
            @endif
            @if($branding['phone'])
                &nbsp;&bull;&nbsp; {{ $branding['phone'] }}
            @endif
        </div>
    </div>
</body>
</html>
