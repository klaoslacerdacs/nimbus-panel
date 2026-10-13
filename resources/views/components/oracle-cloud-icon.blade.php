@php
    $iconAttributes = $attributes->has('class')
        ? $attributes->class('shrink-0')
        : $attributes->merge(['class' => 'size-6 shrink-0']);
@endphp

<svg {{ $iconAttributes }} viewBox="0 0 40 40" xmlns="http://www.w3.org/2000/svg" aria-label="Oracle Cloud">
    <rect width="40" height="40" rx="8" fill="#C74634"/>
    <text x="20" y="26.5" text-anchor="middle" fill="white" font-family="system-ui,sans-serif" font-weight="700" font-size="11" letter-spacing="0.5">OCI</text>
</svg>
