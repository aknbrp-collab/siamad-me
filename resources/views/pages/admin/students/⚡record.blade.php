<?php

use App\Enums\PrintDocument;
use App\Actions\Teaching\AcademicRecord;
use App\Actions\Teaching\CorrectGrade;
use App\Livewire\AcademicRecordPage;
use App\Models\CourseEnrollment;
use App\Models\Student;
use App\Support\MaintenanceMode;
use Flux\Flux;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;

new #[Title('Academic record')] class extends AcademicRecordPage {
    public Student $record;

    // Grade correction (closed semester, maintenance mode).
    public ?int $correctingId = null;
    public ?string $correctScore = null;
    public string $correctLetter = '';
    public string $correctReason = '';

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

    /**
     * Grades of a closed semester can be corrected only in maintenance mode.
     */
    #[Computed]
    public function correctable(): bool
    {
        return $this->semester !== null && $this->semester->isLocked() && MaintenanceMode::allowsEditing();
    }

    public function correctGrade(int $enrollmentId): void
    {
        abort_unless($this->correctable, 403);

        $enrollment = $this->record->courseEnrollments()->findOrFail($enrollmentId);
        $this->correctingId = $enrollment->id;
        $this->correctScore = $enrollment->final_score;
        // A given letter wins over the score, so prefill it only for a letter-only grade.
        $this->correctLetter = $enrollment->final_score === null ? (string) $enrollment->final_letter : '';
        $this->correctReason = '';
        $this->resetErrorBag();
        Flux::modal('correct-grade')->show();
    }

    public function saveCorrection(CorrectGrade $correct): void
    {
        $this->resetErrorBag();
        abort_unless($this->correctable, 403);

        /** @var CourseEnrollment $enrollment */
        $enrollment = $this->record->courseEnrollments()->findOrFail($this->correctingId);
        $correct->handle($enrollment, ['score' => $this->correctScore, 'letter' => $this->correctLetter, 'reason' => $this->correctReason]);

        Flux::modal('correct-grade')->close();
        unset($this->khs, $this->transcript);
        Flux::toast(variant: 'success', text: __('Grade corrected. The change and its reason are in the audit log.'));
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
            <x-khs-table :khs="$this->khs" :attendance="$this->attendance" :minimum="$this->minimum" :correctable="$this->correctable" />
            @if ($this->correctable)
                <flux:text class="mt-2 text-xs">{{ __('Maintenance mode: grades of this closed semester can be corrected. To add a subject, use Previous-year data → Final grades.') }}</flux:text>
            @endif
        @else
            <flux:text>{{ __('Not placed in a class yet.') }}</flux:text>
        @endif
    </div>

    <div>
        <flux:heading size="lg" class="mb-3">{{ __('Transcript') }}</flux:heading>
        <x-transcript-table :transcript="$this->transcript" />
    </div>

    <flux:modal name="correct-grade" class="w-full max-w-md">
        <form wire:submit="saveCorrection" class="space-y-5">
            <div>
                <flux:heading size="lg">{{ __('Correct grade') }}</flux:heading>
                <flux:text class="mt-1">{{ __('For a closed semester, e.g. from the paper archive. Give the score, or the letter from the archive (its point follows the grade scale).') }}</flux:text>
            </div>
            <div class="grid gap-5 sm:grid-cols-2">
                <flux:input wire:model="correctScore" :label="__('Score (0–100)')" inputmode="decimal" />
                <flux:input wire:model="correctLetter" :label="__('Letter (optional)')" placeholder="B+" />
            </div>
            <flux:error name="score" />
            <flux:error name="letter" />
            <flux:textarea wire:model="correctReason" :label="__('Reason')" rows="2" required />
            <flux:error name="reason" />
            <div class="flex justify-end gap-2">
                <flux:modal.close><flux:button variant="ghost">{{ __('Cancel') }}</flux:button></flux:modal.close>
                <flux:button type="submit" variant="primary" data-test="save-correction">{{ __('Save') }}</flux:button>
            </div>
        </form>
    </flux:modal>
</section>
