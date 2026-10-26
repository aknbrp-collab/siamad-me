@props(['transcript'])

<div class="space-y-6">
    <div class="grid gap-4 sm:grid-cols-2">
        <flux:card>
            <flux:text class="text-sm">{{ __('IPK (cumulative)') }}</flux:text>
            <div class="text-3xl font-semibold tabular-nums">{{ $transcript['ipk'] !== null ? number_format($transcript['ipk'], 2) : '—' }}</div>
        </flux:card>
        <flux:card>
            <flux:text class="text-sm">{{ __('Credits earned') }}</flux:text>
            <div class="text-3xl font-semibold tabular-nums">{{ $transcript['credits'] }} <span class="text-base font-normal">{{ __('SKS') }}</span></div>
        </flux:card>
    </div>

    @forelse ($transcript['semesters'] as $entry)
        <div wire:key="transcript-{{ $entry['semester']->id }}">
            <div class="mb-2 flex items-center justify-between">
                <flux:heading>{{ $entry['semester']->label() }}</flux:heading>
                <flux:text class="text-sm">{{ __('IPS :ip · :credits SKS', ['ip' => number_format((float) $entry['ip'], 2), 'credits' => $entry['credits']]) }}</flux:text>
            </div>
            <div class="divide-y divide-zinc-200 rounded-lg border border-zinc-200 text-sm dark:divide-zinc-700 dark:border-zinc-700">
                @foreach ($entry['rows'] as $row)
                    <div class="flex items-center justify-between gap-3 px-3 py-2">
                        <span><span class="font-mono text-zinc-500">{{ $row['code'] }}</span> {{ $row['name'] }}</span>
                        <span class="flex items-center gap-2">
                            <span class="text-zinc-500">{{ $row['credits'] }} {{ __('SKS') }}</span>
                            <flux:badge size="sm">{{ $row['letter'] }}</flux:badge>
                        </span>
                    </div>
                @endforeach
            </div>
        </div>
    @empty
        <flux:text>{{ __('No final grades yet.') }}</flux:text>
    @endforelse
</div>
