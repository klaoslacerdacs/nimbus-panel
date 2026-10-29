@props(['size' => 'sm'])

{{-- nimbus-logo.png is white on transparent: invert it in light mode. --}}
<span {{ $attributes->merge(['class' => 'inline-flex items-center gap-2 min-w-0']) }}>
    <img src="/nimbus-logo.png" alt="Nimbus Network"
        class="{{ $size === 'lg' ? 'h-14' : 'h-7' }} w-auto shrink-0 invert dark:invert-0" />
    <span class="flex min-w-0 flex-col leading-none">
        <span class="flex items-baseline gap-1 min-w-0">
            <span
                class="{{ $size === 'lg' ? 'text-2xl' : 'text-[13px]' }} truncate font-semibold tracking-tight text-black dark:text-white">Coolify</span>
            {{ $slot }}
        </span>
        <span
            class="{{ $size === 'lg' ? 'mt-1.5 text-sm' : 'mt-1 text-[9.5px]' }} truncate font-medium text-neutral-500 dark:text-fg-faint">by
            Nimbus · Oracle Partner</span>
    </span>
</span>
