{{-- The uploaded app logo (Superadmin → Identity), or the built-in mark on a green tile. --}}
@props(['size' => 'size-8'])

@if ($url = \App\Support\Branding::appLogoUrl())
    <img src="{{ $url }}" alt="" {{ $attributes->class([$size, 'object-contain']) }}>
@else
    <span {{ $attributes->class([$size, 'flex items-center justify-center rounded-md bg-accent text-accent-foreground']) }}>
        <x-app-logo-icon class="size-[60%] fill-current" />
    </span>
@endif
