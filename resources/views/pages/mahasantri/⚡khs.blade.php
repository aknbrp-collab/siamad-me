<?php

use App\Actions\Evaluations\ManageEvaluations;
use App\Livewire\AcademicRecordPage;
use App\Models\Student;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;

new #[Title('KHS')] class extends AcademicRecordPage {
    protected function student(): ?Student
    {
        return auth()->user()->student;
    }

    /**
     * The semester's evaluation is open, required for the KHS, and not finished (v6 D35).
     */
    #[Computed]
    public function waitsForEvaluation(): bool
    {
        $student = $this->student();

        return $student !== null && $this->semester !== null && app(ManageEvaluations::class)->blocksKhs($student, $this->semester->id);
    }
}; ?>

<section class="w-full max-w-4xl">
    <x-page-header :title="__('Kartu Hasil Studi')" :subtitle="__('Grades per semester. Only finalised grades count toward the IPS.')">
        <x-slot:actions>
            @if ($this->semesters->isNotEmpty())
                <flux:select wire:model.live="semesterId" class="w-60">
                    @foreach ($this->semesters as $semester)
                        <flux:select.option :value="$semester->id">{{ $semester->label() }}</flux:select.option>
                    @endforeach
                </flux:select>
                @if ($this->semester && ! $this->waitsForEvaluation)
                    <flux:button icon="arrow-down-tray" :href="route('records.khs', [auth()->user()->student, $this->semester])" target="_blank">{{ __('PDF') }}</flux:button>
                @endif
            @endif
        </x-slot:actions>
    </x-page-header>

    @if ($this->waitsForEvaluation)
        <flux:callout icon="star" color="amber" :heading="__('Please evaluate your muhadhir first')" data-test="khs-waits-for-evaluation"
            :text="__('The KHS of this semester opens once you have evaluated all your classes kuliah.')">
            <x-slot name="actions">
                <flux:button size="sm" :href="route('mahasantri.evaluations')" wire:navigate>{{ __('Evaluate now') }}</flux:button>
            </x-slot>
        </flux:callout>
    @elseif ($this->khs)
        <x-khs-table :khs="$this->khs" :attendance="$this->attendance" :minimum="$this->minimum" />
    @else
        <flux:text>{{ __('You are not placed in a class yet.') }}</flux:text>
    @endif
</section>
