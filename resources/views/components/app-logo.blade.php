@props([
    'sidebar' => false,
])

@if($sidebar)
    <flux:sidebar.brand :name="\App\Models\Setting::get('institution.short_name') ?: config('app.name', 'Laravel')" {{ $attributes }}>
        <x-slot name="logo">
            <x-app-logo-mark />
        </x-slot>
    </flux:sidebar.brand>
@else
    <flux:brand :name="\App\Models\Setting::get('institution.short_name') ?: config('app.name', 'Laravel')" {{ $attributes }}>
        <x-slot name="logo">
            <x-app-logo-mark />
        </x-slot>
    </flux:brand>
@endif
