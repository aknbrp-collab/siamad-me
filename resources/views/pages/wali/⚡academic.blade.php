<?php

use App\Livewire\AcademicRecordPage;
use App\Models\Student;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;

new #[Title('Academic progress')] class extends AcademicRecordPage {
    #[Url(as: 'child')]
    public ?int $childId = null;

    /**
     * The guardian's own children only.
     *
     * @return Collection<int, Student>
     */
    #[Computed]
    public function children(): Collection
    {
        return auth()->user()->guardian?->students()->with('user')->orderBy('nim')->get() ?? new Collection;
    }

    protected function student(): ?Student
    {
        return $this->children->firstWhere('id', $this->childId) ?? $this->children->first();
    }

    /**
     * Recent pesantren activities of the selected child (monitoring only).
     *
     * @return Collection<int, \App\Models\ActivityLog>
     */
    #[Computed]
    public function activities(): Collection
    {
        return $this->student()?->activityLogs()->limit(10)->get() ?? new Collection;
    }

    public function updatedChildId(): void
    {
        $this->semesterId = null;
        unset($this->semesters, $this->semester, $this->khs, $this->attendance, $this->activities);
    }
}; ?>

<section class="w-full max-w-4xl">
    <x-page-header :title="__('Academic progress')" :subtitle="__('Grades and attendance of your children, per semester.')">
        <x-slot:actions>
            @if ($this->children->count() > 1)
                <flux:select wire:model.live="childId" class="w-56">
                    @foreach ($this->children as $child)
                        <flux:select.option :value="$child->id">{{ $child->user->name }}</flux:select.option>
                    @endforeach
                </flux:select>
            @endif
            @if ($this->semesters->isNotEmpty())
                <flux:select wire:model.live="semesterId" class="w-56">
                    @foreach ($this->semesters as $semester)
                        <flux:select.option :value="$semester->id">{{ $semester->label() }}</flux:select.option>
                    @endforeach
                </flux:select>
            @endif
        </x-slot:actions>
    </x-page-header>

    @php($child = $this->children->firstWhere('id', $childId) ?? $this->children->first())

    @if ($child === null)
        <flux:text>{{ __('No mahasantri are linked to your account. Please contact the academic office.') }}</flux:text>
    @else
        <div class="mb-4 flex flex-wrap items-center justify-between gap-2">
            <flux:heading size="lg">{{ $child->user->name }} <span class="font-mono text-sm text-zinc-500">{{ $child->nim }}</span></flux:heading>
            <div class="flex gap-2">
                @if ($this->semester)
                    <flux:button size="sm" icon="arrow-down-tray" :href="route('records.khs', [$child, $this->semester])" target="_blank">{{ __('KHS PDF') }}</flux:button>
                @endif
                <flux:button size="sm" icon="arrow-down-tray" :href="route('records.transcript', $child)" target="_blank">{{ __('Transcript PDF') }}</flux:button>
            </div>
        </div>

        @if ($this->khs)
            <x-khs-table :khs="$this->khs" :attendance="$this->attendance" :minimum="$this->minimum" />
        @else
            <flux:text>{{ __('Not placed in a class yet.') }}</flux:text>
        @endif

        <div class="mt-8">
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
    @endif
</section>
