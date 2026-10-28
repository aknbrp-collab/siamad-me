{{-- Reminder for mahasantri / ustadz with missing or rejected documents. Expects $role. --}}
@php
    $owner = match ($role) {
        \App\Enums\Role::Student => auth()->user()->student,
        \App\Enums\Role::Teacher => auth()->user()->teacher,
        default => null,
    };
    $pendingDocuments = $owner?->documents()
        ->whereIn('status', [\App\Enums\DocumentStatus::Missing, \App\Enums\DocumentStatus::Rejected])
        ->orderBy('id')
        ->get() ?? collect();
@endphp

@if ($pendingDocuments->isNotEmpty())
    <flux:callout
        :color="$role === \App\Enums\Role::Student ? 'amber' : 'blue'"
        icon="document-text"
        :heading="$role === \App\Enums\Role::Student ? __('Please upload your documents') : __('Documents requested')"
        data-test="documents-reminder"
    >
        <flux:callout.text>
            {{ $pendingDocuments->map(fn ($document) => $document->title().' ('.$document->status->label().')')->join(', ') }}
        </flux:callout.text>
        <x-slot name="actions">
            <flux:button size="sm" :href="route('documents.mine')" wire:navigate>{{ __('Upload now') }}</flux:button>
        </x-slot>
    </flux:callout>
@endif
