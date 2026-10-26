<?php

use App\Actions\Supervision\ManageSupervision;
use App\Actions\Teaching\AcademicRecord;
use App\Livewire\AcademicRecordPage;
use App\Models\ActivityLog;
use App\Models\Student;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;

new #[Title('Supervisee')] class extends AcademicRecordPage {
    public Student $record;

    public function mount(Student $student): void
    {
        // Only the current pembimbing akademik (read-only view).
        abort_unless(ManageSupervision::supervises(auth()->user(), $student), 403);

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
     * @return Collection<int, ActivityLog>
     */
    #[Computed]
    public function activities(): Collection
    {
        return $this->record->activityLogs()->limit(10)->get();
    }
}; ?>

<section class="w-full max-w-5xl space-y-8">
    <x-page-header :title="$record->user->name" :subtitle="__('NIM :nim · :program · angkatan :year', ['nim' => $record->nim, 'program' => $record->studyProgram->name, 'year' => $record->entry_year])">
        <x-slot:actions>
            <flux:button size="sm" variant="ghost" icon="arrow-left" :href="route('ustadz.supervision')" wire:navigate>{{ __('My supervisees') }}</flux:button>
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

    <div>
        <flux:heading size="lg" class="mb-3">{{ __('Pesantren activities') }}</flux:heading>
        <div class="divide-y divide-zinc-200 rounded-lg border border-zinc-200 dark:divide-zinc-700 dark:border-zinc-700">
            @forelse ($this->activities as $activity)
                <div wire:key="activity-{{ $activity->id }}" class="flex flex-wrap items-center gap-2 px-3 py-2 text-sm">
                    <span class="w-24 text-zinc-500">{{ $activity->happened_on->translatedFormat('j M Y') }}</span>
                    <flux:badge size="sm">{{ $activity->category->label() }}</flux:badge>
                    <span>{{ $activity->title }}</span>
                </div>
            @empty
                <flux:text class="p-3 text-sm">{{ __('No activities recorded yet.') }}</flux:text>
            @endforelse
        </div>
    </div>
</section>
