{{-- Mahasantri: classes kuliah still to evaluate while the evaluation is open (v6 D35). Expects $role. --}}
@php
    $student = $role === \App\Enums\Role::Student ? auth()->user()->student : null;
    $pendingEvaluations = $student ? app(\App\Actions\Evaluations\ManageEvaluations::class)->forStudent($student)->reject(fn (array $row) => $row['done']) : collect();
@endphp

@if ($pendingEvaluations->isNotEmpty())
    <flux:callout color="amber" icon="star" :heading="__('Evaluate your muhadhir')" data-test="evaluation-reminder">
        <flux:callout.text>
            {{ trans_choice(':count class kuliah waits for your evaluation, until :date. Answers are anonymous.|:count classes kuliah wait for your evaluation, until :date. Answers are anonymous.', $pendingEvaluations->count(), ['date' => $pendingEvaluations->first()['period']->closes_on->translatedFormat('j F Y')]) }}
        </flux:callout.text>
        <x-slot name="actions">
            <flux:button size="sm" :href="route('mahasantri.evaluations')" wire:navigate>{{ __('Evaluate now') }}</flux:button>
        </x-slot>
    </flux:callout>
@endif
