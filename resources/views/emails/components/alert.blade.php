{{-- Email Alert Component --}}
@php
    $alertClass = 'alert alert-' . ($type ?? 'info');
    
    $styles = [
        'info' => 'background: #eff6ff; border-left-color: #2563eb; color: #1e40af;',
        'success' => 'background: #ecfdf5; border-left-color: #059669; color: #047857;',
        'warning' => 'background: #fffbeb; border-left-color: #d97706; color: #92400e;',
        'error' => 'background: #fef2f2; border-left-color: #dc2626; color: #b91c1c;'
    ];
    
    $style = $styles[$type ?? 'info'] ?? $styles['info'];
    
    $icons = [
        'info' => '💡',
        'success' => '✅',
        'warning' => '⚠️',
        'error' => '❌'
    ];
    
    $icon = $icons[$type ?? 'info'] ?? $icons['info'];
@endphp

<div class="{{ $alertClass }}" 
     style="padding: 16px 20px; border-radius: 8px; margin: 20px 0; border-left: 4px solid; {{ $style }}">
    @if(!isset($hideIcon) || !$hideIcon)
        <span style="margin-right: 8px;">{{ $icon }}</span>
    @endif
    {{ $slot }}
</div>
