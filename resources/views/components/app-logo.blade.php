@props([
    'sidebar' => false,
])

@if($sidebar)
    <flux:sidebar.brand :name="\App\Support\Branding::appShortName()" {{ $attributes }}>
        <x-slot name="logo">
            <x-app-logo-mark />
        </x-slot>
    </flux:sidebar.brand>
@else
    <flux:brand :name="\App\Support\Branding::appShortName()" {{ $attributes }}>
        <x-slot name="logo">
            <x-app-logo-mark />
        </x-slot>
    </flux:brand>
@endif
