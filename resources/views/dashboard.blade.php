@php
    /** @var \App\Enums\Role $role */
    $upcoming = match ($role) {
        \App\Enums\Role::Superadmin => [__('Users & roles'), __('Backups')],
        \App\Enums\Role::Admin => [__('Attendance overview'), __('KHS & transcripts')],
        \App\Enums\Role::AdminPayment => [__('Fee structure'), __('Bills'), __('Payments & handovers'), __('Scholarships')],
        \App\Enums\Role::AcademicMonitor => [__('Attendance overview'), __('Academic progress'), __('Activity log')],
        \App\Enums\Role::Teacher => [__('My classes'), __('Attendance'), __('Grades'), __('Assignments & quizzes')],
        \App\Enums\Role::Student => [__('Schedule'), __('My classes'), __('KHS & transcript')],
        \App\Enums\Role::Guardian => [__('Academic progress'), __('Attendance')],
    };
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
            <flux:heading size="lg" level="2">{{ __('Coming soon') }}</flux:heading>
            <div class="mt-3 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                @foreach ($upcoming as $module)
                    <div class="rounded-xl border border-dashed border-zinc-300 p-4 dark:border-zinc-600">
                        <flux:text class="font-medium text-zinc-700 dark:text-zinc-200">{{ $module }}</flux:text>
                    </div>
                @endforeach
            </div>
        </div>
    </div>
</x-layouts::app>
