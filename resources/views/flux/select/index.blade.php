@blaze

{{-- SIAKAD: not folded, so the default variant can look at the options at render time. --}}

@props([
    'variant' => 'default',
])

<flux:with-field :$attributes>
    <flux:delegate-component :component="'select.variants.' . $variant">{{ $slot }}</flux:delegate-component>
</flux:with-field>
