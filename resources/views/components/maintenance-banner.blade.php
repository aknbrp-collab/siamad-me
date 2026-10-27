{{--
    Shown to staff on every page while maintenance mode is on. Worded in English on
    purpose (v3 plan, D6), so it reads the same for everyone.
--}}
@php
    $role = \App\Support\ActiveRole::get();
    $session = $role?->isStaff() ? \App\Support\MaintenanceMode::current() : null;
@endphp

@if ($session)
    <div class="mb-6 flex flex-wrap items-start gap-3 rounded-lg border border-amber-300 bg-amber-50 px-4 py-3 text-sm text-amber-900 dark:border-amber-500/40 dark:bg-amber-500/10 dark:text-amber-200" role="status" data-test="maintenance-banner">
        <flux:icon.wrench-screwdriver class="mt-0.5 size-5 shrink-0" />
        <p class="min-w-0 flex-1">
            <strong>Maintenance mode:</strong> previous-year data entry is open.
            Turned on by {{ $session->starter->name }} on {{ $session->started_at->locale('en')->isoFormat('D MMMM YYYY, HH:mm') }} — {{ $session->reason }}
        </p>
        @if ($role === \App\Enums\Role::Superadmin)
            <a href="{{ route('superadmin.maintenance') }}" class="font-medium underline" wire:navigate>Manage</a>
        @endif
    </div>
@endif
