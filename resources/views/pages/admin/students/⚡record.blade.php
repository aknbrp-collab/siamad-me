<?php

use App\Enums\PrintDocument;
use App\Actions\Teaching\AcademicRecord;
use App\Livewire\AcademicRecordPage;
use App\Models\Student;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;

new #[Title('Academic record')] class extends AcademicRecordPage {
    public Student $record;

    public function mount(Student $student): void
    {
        $this->record = $student->load(['user', 'studyProgram']);
    }

    protected function student(): ?Student
    {
        return $this->record;
    }

    /**
     * @return array<string, mixed>
     */
    #[Computed]
    public function transcript(): array
    {
        return app(AcademicRecord::class)->transcript($this->record);
    }
}; ?>

<section class="w-full max-w-5xl space-y-8">
    <x-page-header :title="$record->user->name" :subtitle="__('NIM :nim · :program · angkatan :year', ['nim' => $record->nim, 'program' => $record->studyProgram->name, 'year' => $record->entry_year])">
        <x-slot:actions>
            <x-print-button size="sm" icon="printer" :href="route('records.transcript', $record)" :document="PrintDocument::Transcript">{{ __('Transcript PDF') }}</x-print-button>
            <flux:button size="sm" variant="ghost" icon="arrow-left" :href="route('admin.students.edit', $record)" wire:navigate>{{ __('Biodata') }}</flux:button>
        </x-slot:actions>
    </x-page-header>

    <div>
        <div class="mb-3 flex flex-wrap items-center gap-2">
            <flux:heading size="lg" class="me-auto">{{ __('KHS') }}</flux:heading>
            @if ($this->semesters->isNotEmpty())
                <flux:select wire:model.live="semesterId" size="sm" class="w-60">
                    @foreach ($this->semesters as $semester)
                        <flux:select.option :value="$semester->id">{{ $semester->label() }}</flux:select.option>
                    @endforeach
                </flux:select>
                @if ($this->semester)
                    <x-print-button size="sm" icon="printer" :href="route('records.khs', [$record, $this->semester])" :document="PrintDocument::Khs">{{ __('PDF') }}</x-print-button>
                @endif
            @endif
        </div>
        @if ($this->khs)
            <x-khs-table :khs="$this->khs" :attendance="$this->attendance" :minimum="$this->minimum" />
        @else
            <flux:text>{{ __('Not placed in a class yet.') }}</flux:text>
        @endif
    </div>

    <div>
        <flux:heading size="lg" class="mb-3">{{ __('Transcript') }}</flux:heading>
        <x-transcript-table :transcript="$this->transcript" />
    </div>
</section>
