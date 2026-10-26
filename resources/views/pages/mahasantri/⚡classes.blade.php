<?php

use App\Models\CourseOffering;
use App\Models\Semester;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('My classes')] class extends Component {
    /**
     * This semester's offerings of the mahasantri, in timetable order.
     *
     * @return Collection<int, CourseOffering>
     */
    #[Computed]
    public function offerings(): Collection
    {
        $student = auth()->user()->student;
        $semester = Semester::active();

        if ($student === null || $semester === null) {
            return new Collection;
        }

        return CourseOffering::query()
            ->with(['teacher.user', 'classGroup'])
            ->withCount([
                'materials',
                'assignments as open_assignments_count' => fn ($query) => $query->where('due_at', '>', now()),
                'quizzes as open_quizzes_count' => fn ($query) => $query->whereNotNull('published_at')->where('opens_at', '<=', now())->where('closes_at', '>', now()),
            ])
            ->where('semester_id', $semester->id)
            ->whereHas('enrollments', fn ($query) => $query->where('student_id', $student->id))
            ->orderByRaw('day_of_week is null')
            ->orderBy('day_of_week')
            ->orderBy('starts_at')
            ->get();
    }
}; ?>

<section class="w-full max-w-4xl">
    <x-page-header :title="__('My classes')" :subtitle="__('Your subjects this semester, in timetable order.')" />

    <div class="flex flex-col gap-3">
        @forelse ($this->offerings as $offering)
            <a wire:key="offering-{{ $offering->id }}" href="{{ route('mahasantri.class', $offering) }}" wire:navigate
                class="block rounded-xl border border-zinc-200 p-4 transition hover:border-zinc-400 dark:border-zinc-700 dark:hover:border-zinc-500">
                <div class="flex flex-wrap items-start justify-between gap-2">
                    <div>
                        <div class="font-medium">{{ $offering->subject_name }}</div>
                        <flux:text class="text-sm">{{ $offering->subject_code }} · {{ $offering->credits }} {{ __('SKS') }} · {{ $offering->teacher->displayName() }}</flux:text>
                    </div>
                    <flux:badge size="sm" icon="clock">{{ $offering->scheduleLabel() }}</flux:badge>
                </div>
                <div class="mt-2 flex flex-wrap gap-2">
                    @if ($offering->open_assignments_count > 0)
                        <flux:badge size="sm" color="amber">{{ trans_choice(':count open assignment|:count open assignments', $offering->open_assignments_count) }}</flux:badge>
                    @endif
                    @if ($offering->open_quizzes_count > 0)
                        <flux:badge size="sm" color="blue">{{ trans_choice(':count quiz open now|:count quizzes open now', $offering->open_quizzes_count) }}</flux:badge>
                    @endif
                    <flux:badge size="sm">{{ trans_choice(':count material|:count materials', $offering->materials_count) }}</flux:badge>
                </div>
            </a>
        @empty
            <flux:text>{{ __('You have no classes in the active semester.') }}</flux:text>
        @endforelse
    </div>
</section>
