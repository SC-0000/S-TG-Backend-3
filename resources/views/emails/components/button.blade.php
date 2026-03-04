{{-- Email Button Component --}}
@php
    $buttonClass = 'btn';
    $buttonClass .= isset($variant) ? ' btn-' . $variant : '';
    $href = $href ?? '#';
    $target = $target ?? '_blank';
    $resolvedColor = $color ?? ($emailButtonColor ?? '#2563eb');
    $resolvedColorEnd = $colorEnd ?? ($emailButtonColorSecondary ?? ($emailHeaderColorSecondary ?? '#1d4ed8'));
@endphp

<!--[if mso]>
<v:roundrect xmlns:v="urn:schemas-microsoft-com:vml" xmlns:w="urn:schemas-microsoft-com:office:office" 
    href="{{ $href }}" 
    style="height:52px;v-text-anchor:middle;width:{{ $width ?? '200' }}px;" 
    arcsize="15%" 
    stroke="f" 
    fillcolor="{{ $resolvedColor }}">
    <w:anchorlock/>
    <center style="color:#ffffff;font-family:Arial,sans-serif;font-size:16px;font-weight:bold;">
        {{ $slot }}
    </center>
</v:roundrect>
<![endif]-->

<!--[if !mso]><!-->
<a href="{{ $href }}" 
   target="{{ $target }}" 
   class="{{ $buttonClass }}"
   style="display: inline-block; padding: 16px 32px; background: linear-gradient(135deg, {{ $resolvedColor }} 0%, {{ $resolvedColorEnd }} 100%); color: #ffffff !important; text-decoration: none !important; border-radius: 8px; font-weight: 600; font-size: 16px; text-align: center; border: none; cursor: pointer; box-shadow: 0 4px 12px rgba(37, 99, 235, 0.3); mso-hide: all;">
    {{ $slot }}
</a>
<!--<![endif]-->
