<?php

use App\Enums\Role;
use App\Models\CourseOffering;
use App\Models\Semester;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

new #[Title('Dashboard')] class extends Component {
    #[Url(as: 'semester')]
    public ?int $semesterId = null;

    public function mount(): void
    {
        $this->semesterId ??= Semester::active()?->id ?? $this->semesters->first()?->id;
    }

    /**
     * Semesters in which this ustadz teaches, newest first.
     *
     * @return Collection<int, Semester>
     */
    #[Computed]
    public function semesters(): Collection
    {
        $teacherId = auth()->user()->teacher?->id;

        return Semester::query()
            ->with('academicYear')
            ->whereHas('courseOfferings', fn ($query) => $query->where('teacher_id', $teacherId))
            ->orWhere('status', 'active')
            ->orderByDesc('academic_year_id')->orderByDesc('term')
            ->get();
    }

    /**
     * @return Collection<int, CourseOffering>
     */
    #[Computed]
    public function offerings(): Collection
    {
        return CourseOffering::query()
            ->with(['classGroup', 'semester'])
            ->withCount(['enrollments', 'sessions', 'components'])
            ->where('teacher_id', auth()->user()->teacher?->id)
            ->where('semester_id', $this->semesterId)
            ->orderBy('day_of_week')->orderBy('starts_at')
            ->get();
    }
}; ?>

<section class="w-full space-y-6">
    <x-page-header :title="__('Assalamu\'alaikum, :name', ['name' => auth()->user()->name])" :subtitle="Role::Teacher->label().' · '.\App\Models\Setting::get('institution.name')">
        <x-slot:actions>
            @if ($this->semesters->count() > 1)
                <flux:select wire:model.live="semesterId" class="w-60">
                    @foreach ($this->semesters as $semester)
                        <flux:select.option :value="$semester->id">{{ $semester->label() }}</flux:select.option>
                    @endforeach
                </flux:select>
            @endif
        </x-slot:actions>
    </x-page-header>

    @include('partials.documents-reminder', ['role' => Role::Teacher])

    <div>
        <flux:heading size="lg" class="mb-3">{{ __('My classes') }}</flux:heading>
        <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
            @forelse ($this->offerings as $offering)
                <flux:card wire:key="offering-{{ $offering->id }}" class="flex flex-col gap-3">
                    <div>
                        <flux:heading>{{ $offering->subject_name }}</flux:heading>
                        <flux:text class="text-sm">{{ $offering->subject_code }} · {{ $offering->credits }} {{ __('SKS') }} · {{ $offering->classGroup->name }}</flux:text>
                        <flux:text class="text-sm">{{ $offering->scheduleLabel() }}</flux:text>
                    </div>
                    <div class="flex flex-wrap gap-2 text-xs">
                        <flux:badge size="sm">{{ trans_choice(':count mahasantri|:count mahasantri', $offering->enrollments_count) }}</flux:badge>
                        <flux:badge size="sm">{{ trans_choice(':count meeting|:count meetings', $offering->sessions_count) }}</flux:badge>
                        @if ($offering->isFinalized())
                            <flux:badge size="sm" color="green">{{ __('Grades final') }}</flux:badge>
                        @elseif ($offering->components_count === 0)
                            <flux:badge size="sm" color="amber">{{ __('No grade components') }}</flux:badge>
                        @endif
                    </div>
                    <div class="mt-auto flex gap-2">
                        <flux:button size="sm" icon="calendar-days" :href="route('ustadz.offerings.meetings', $offering)" wire:navigate>{{ __('Meetings') }}</flux:button>
                        <flux:button size="sm" icon="calculator" :href="route('ustadz.offerings.grades', $offering)" wire:navigate>{{ __('Grades') }}</flux:button>
                    </div>
                </flux:card>
            @empty
                <flux:text>{{ __('You have no classes in this semester.') }}</flux:text>
            @endforelse
        </div>
    </div>
</section>
