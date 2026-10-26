@php
    /** @var \App\Enums\Role $role */
    // Shortcuts are the role's own sidebar menu (minus the dashboard), so they always match what exists.
    $shortcuts = collect($role->navigation())
        ->flatMap(fn (array $group) => $group['items'])
        ->reject(fn (array $item) => $item['route'] === $role->homeRoute())
        ->values();
@endphp

<x-layouts::app :title="__('Dashboard')">
    <div class="flex w-full flex-1 flex-col gap-6">
        <div class="flex items-start gap-4">
            <div class="flex size-12 shrink-0 items-center justify-center rounded-lg bg-zinc-100 dark:bg-zinc-700">
                <flux:icon :icon="$role->icon()" class="size-6 text-zinc-600 dark:text-zinc-300" />
            </div>
            <div>
                <flux:heading size="xl" level="1">{{ __('Assalamu\'alaikum, :name', ['name' => auth()->user()->name]) }}</flux:heading>
                <flux:text class="mt-1">
                    {{ $role->label() }} · {{ \App\Models\Setting::get('institution.name') }}
                </flux:text>
            </div>
        </div>

        <flux:text>{{ $role->description() }}</flux:text>

        @include('partials.documents-reminder', ['role' => $role])

        <div>
            <flux:heading size="lg" level="2">{{ __('Quick links') }}</flux:heading>
            <div class="mt-3 grid gap-3 sm:grid-cols-2 lg:grid-cols-4" data-test="shortcuts">
                @foreach ($shortcuts as $item)
                    <a href="{{ route($item['route']) }}" wire:navigate
                        class="flex items-center gap-3 rounded-xl border border-zinc-200 p-4 transition hover:border-zinc-300 hover:bg-zinc-50 dark:border-zinc-700 dark:hover:border-zinc-600 dark:hover:bg-zinc-800">
                        <flux:icon :icon="$item['icon']" class="size-5 shrink-0 text-zinc-500 dark:text-zinc-400" />
                        <span class="font-medium text-zinc-800 dark:text-zinc-100">{{ $item['label'] }}</span>
                    </a>
                @endforeach
            </div>
        </div>
    </div>
</x-layouts::app>
