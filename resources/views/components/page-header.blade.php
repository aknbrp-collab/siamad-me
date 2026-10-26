@props([
    'title',
    'subtitle' => null,
])

<div class="mb-6 flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
    <div>
        <flux:heading size="xl" level="1">{{ $title }}</flux:heading>
        @if ($subtitle)
            <flux:subheading>{{ $subtitle }}</flux:subheading>
        @endif
    </div>

    @isset($actions)
        <div class="flex flex-wrap items-center gap-2">
            {{ $actions }}
        </div>
    @endisset
</div>
