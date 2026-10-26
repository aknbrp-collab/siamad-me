<?php

use App\Livewire\AcademicRecordPage;
use App\Models\Student;
use Livewire\Attributes\Title;

new #[Title('KHS')] class extends AcademicRecordPage {
    protected function student(): ?Student
    {
        return auth()->user()->student;
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
                @if ($this->semester)
                    <flux:button icon="arrow-down-tray" :href="route('records.khs', [auth()->user()->student, $this->semester])" target="_blank">{{ __('PDF') }}</flux:button>
                @endif
            @endif
        </x-slot:actions>
    </x-page-header>

    @if ($this->khs)
        <x-khs-table :khs="$this->khs" :attendance="$this->attendance" :minimum="$this->minimum" />
    @else
        <flux:text>{{ __('You are not placed in a class yet.') }}</flux:text>
    @endif
</section>
