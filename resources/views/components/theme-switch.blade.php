{{-- Light / dark / system, remembered per browser (Flux appearance). --}}
<flux:radio.group x-data variant="segmented" x-model="$flux.appearance" size="sm" {{ $attributes }}>
    <flux:radio value="light" icon="sun" :aria-label="__('Light')" />
    <flux:radio value="dark" icon="moon" :aria-label="__('Dark')" />
    <flux:radio value="system" icon="computer-desktop" :aria-label="__('System')" />
</flux:radio.group>
