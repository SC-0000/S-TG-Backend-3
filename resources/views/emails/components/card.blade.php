{{-- Email Card Component --}}
@php
    $cardClass = 'card';
    if (isset($type)) {
        $cardClass .= ' card-' . $type;
    }
@endphp

<div class="{{ $cardClass }}" style="background: #ffffff; border: 1px solid #e5e7eb; border-radius: 8px; padding: 24px; margin: 20px 0; box-shadow: 0 2px 4px rgba(0, 0, 0, 0.05);">
    @if(isset($title))
        <div class="card-header" style="background: #f9fafb; padding: 20px 24px; border-bottom: 1px solid #e5e7eb; border-radius: 8px 8px 0 0; margin: -24px -24px 20px -24px;">
            <h3 class="card-title" style="font-size: 18px; font-weight: 600; color: #1f2937; margin: 0;">{{ $title }}</h3>
        </div>
    @endif
    
    <div class="card-content">
        {{ $slot }}
    </div>
</div>
