<?php

use App\Actions\Supervision\ManageSupervision;
use App\Enums\StudentStatus;
use App\Enums\TeacherStatus;
use App\Models\ClassEnrollment;
use App\Models\ClassGroup;
use App\Models\Semester;
use App\Models\Student;
use App\Models\Supervision;
use App\Models\Teacher;
use Flux\Flux;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

new #[Title('Academic supervisors')] class extends Component {
    #[Url(as: 'muhadhir')]
    public ?int $teacherId = null;

    /** Candidate filters. */
    public ?int $classId = null;
    public bool $onlyUnsupervised = true;
    public string $search = '';

    /** @var list<int> */
    public array $selected = [];

    /**
     * Active muhadhir with the number of mahasantri they supervise now.
     *
     * @return Collection<int, Teacher>
     */
    #[Computed]
    public function teachers(): Collection
    {
        return Teacher::query()
            ->with('user')
            ->withCount(['supervisions as supervisees_count' => fn ($query) => $query->whereNull('ended_on')])
            ->where('status', TeacherStatus::Active)
            ->get()
            ->sortBy('user.name')
            ->values();
    }

    #[Computed]
    public function teacher(): ?Teacher
    {
        return $this->teachers->firstWhere('id', $this->teacherId);
    }

    #[Computed]
    public function unsupervisedCount(): int
    {
        return Student::query()->where('status', StudentStatus::Active)->doesntHave('currentSupervision')->count();
    }

    /**
     * Class names of the active semester, by student id.
     *
     * @return array<int, string>
     */
    #[Computed]
    public function classNames(): array
    {
        $semester = Semester::active();

        return $semester === null ? [] : ClassEnrollment::query()
            ->where('semester_id', $semester->id)
            ->pluck('class_name', 'student_id')
            ->all();
    }

    /**
     * @return Collection<int, Supervision>
     */
    #[Computed]
    public function supervisees(): Collection
    {
        if ($this->teacher === null) {
            return new Collection;
        }

        return Supervision::query()->current()
            ->with('student.user')
            ->where('teacher_id', $this->teacher->id)
            ->get()
            ->sortBy('student.user.name')
            ->values();
    }

    /**
     * @return Collection<int, ClassGroup>
     */
    #[Computed]
    public function classes(): Collection
    {
        return ClassGroup::query()->orderBy('level')->orderBy('name')->get();
    }

    /**
     * Active mahasantri to choose from, with their current supervisor.
     *
     * @return Collection<int, Student>
     */
    #[Computed]
    public function candidates(): Collection
    {
        if ($this->teacher === null) {
            return new Collection;
        }

        $semester = Semester::active();
        $term = '%'.mb_strtolower(trim($this->search)).'%';

        return Student::query()
            ->with(['user', 'currentSupervision.teacher.user'])
            ->where('status', StudentStatus::Active)
            ->whereDoesntHave('currentSupervision', fn ($query) => $query->where('teacher_id', $this->teacher->id))
            ->when($this->onlyUnsupervised, fn (Builder $query) => $query->doesntHave('currentSupervision'))
            ->when($this->classId && $semester, fn (Builder $query) => $query->whereHas('classEnrollments', fn ($query) => $query
                ->where('semester_id', $semester->id)
                ->where('class_group_id', $this->classId)))
            ->when(trim($this->search) !== '', fn (Builder $query) => $query->where(fn (Builder $query) => $query
                ->whereRaw('lower(nim) like ?', [$term])
                ->orWhereHas('user', fn (Builder $query) => $query->whereRaw('lower(name) like ?', [$term]))))
            ->orderBy('nim')
            ->limit(100)
            ->get();
    }

    public function choose(int $teacherId): void
    {
        $this->teacherId = $teacherId;
        $this->selected = [];
        $this->resetErrorBag();
    }

    public function updated(string $property): void
    {
        if (in_array($property, ['classId', 'onlyUnsupervised', 'search'], true)) {
            $this->selected = [];
        }
    }

    public function assign(ManageSupervision $manage): void
    {
        $this->resetErrorBag();
        abort_if($this->teacher === null, 404);

        $assigned = $manage->assign($this->teacher, array_values(array_map('intval', $this->selected)), auth()->user());

        $this->selected = [];
        unset($this->teachers, $this->supervisees, $this->candidates, $this->unsupervisedCount);
        Flux::toast(variant: 'success', text: trans_choice(':count mahasantri assigned to :name.|:count mahasantri assigned to :name.', $assigned, ['name' => $this->teacher->user->name]));
    }

    public function end(int $supervisionId, ManageSupervision $manage): void
    {
        $manage->end(Supervision::query()->current()->where('teacher_id', $this->teacherId)->findOrFail($supervisionId));

        unset($this->teachers, $this->supervisees, $this->candidates, $this->unsupervisedCount);
        Flux::toast(text: __('Supervision ended. It stays in the history.'));
    }
}; ?>

<section class="w-full">
    <x-page-header :title="__('Academic supervisors')"
        :subtitle="__('Each mahasantri has one pembimbing akademik (a muhadhir) at a time. Changing it keeps the earlier one in the history.')" />

    @if ($this->unsupervisedCount > 0)
        <flux:callout icon="information-circle" class="mb-6"
            :text="trans_choice(':count active mahasantri have no pembimbing akademik yet.|:count active mahasantri have no pembimbing akademik yet.', $this->unsupervisedCount)" />
    @endif

    <div class="grid gap-6 lg:grid-cols-[18rem_1fr]">
        <div class="flex flex-col gap-1">
            @forelse ($this->teachers as $teacher)
                <button type="button" wire:key="teacher-{{ $teacher->id }}" wire:click="choose({{ $teacher->id }})"
                    @class([
                        'flex items-center justify-between rounded-lg px-3 py-2 text-start text-sm transition',
                        'bg-zinc-100 font-medium dark:bg-zinc-700' => $teacherId === $teacher->id,
                        'hover:bg-zinc-50 dark:hover:bg-zinc-800' => $teacherId !== $teacher->id,
                    ])>
                    <span>{{ $teacher->displayName() }}</span>
                    <flux:badge size="sm">{{ $teacher->supervisees_count }}</flux:badge>
                </button>
            @empty
                <flux:text class="text-sm">{{ __('No muhadhir found.') }}</flux:text>
            @endforelse
        </div>

        <div>
            @if ($this->teacher)
                <flux:heading size="lg">{{ $this->teacher->displayName() }}</flux:heading>
                <flux:text class="mb-4 text-sm">{{ trans_choice(':count mahasantri bimbingan|:count mahasantri bimbingan', $this->supervisees->count()) }}</flux:text>

                <div class="mb-8 divide-y divide-zinc-200 rounded-lg border border-zinc-200 dark:divide-zinc-700 dark:border-zinc-700">
                    @forelse ($this->supervisees as $supervision)
                        <div wire:key="supervisee-{{ $supervision->id }}" class="flex flex-wrap items-center justify-between gap-2 px-3 py-2 text-sm">
                            <span>
                                <span class="font-mono text-zinc-500">{{ $supervision->student->nim }}</span>
                                <span class="ms-2 font-medium">{{ $supervision->student->user->name }}</span>
                                <span class="ms-2 text-zinc-500">{{ $this->classNames[$supervision->student_id] ?? '—' }} · {{ __('since :date', ['date' => $supervision->started_on->translatedFormat('j M Y')]) }}</span>
                            </span>
                            <flux:button size="xs" variant="ghost" icon="x-mark" wire:click="end({{ $supervision->id }})"
                                wire:confirm="{{ __('End this supervision? It stays in the history.') }}">{{ __('End') }}</flux:button>
                        </div>
                    @empty
                        <flux:text class="p-3 text-sm">{{ __('No mahasantri assigned yet.') }}</flux:text>
                    @endforelse
                </div>

                <flux:heading class="mb-3">{{ __('Add mahasantri') }}</flux:heading>
                <div class="mb-3 flex flex-col gap-3 sm:flex-row sm:items-center">
                    <flux:select wire:model.live="classId" class="sm:max-w-56">
                        <flux:select.option value="">{{ __('All classes') }}</flux:select.option>
                        @foreach ($this->classes as $class)
                            <flux:select.option :value="$class->id">{{ $class->name }}</flux:select.option>
                        @endforeach
                    </flux:select>
                    <flux:input wire:model.live.debounce.300ms="search" icon="magnifying-glass" :placeholder="__('NIM or name…')" class="sm:max-w-56" />
                    <flux:checkbox wire:model.live="onlyUnsupervised" :label="__('Without a supervisor only')" />
                </div>

                <flux:checkbox.group wire:model="selected" class="mb-4 max-h-96 overflow-y-auto rounded-lg border border-zinc-200 p-3 dark:border-zinc-700">
                    @forelse ($this->candidates as $candidate)
                        <flux:checkbox wire:key="candidate-{{ $candidate->id }}" :value="$candidate->id"
                            :label="$candidate->nim.' · '.$candidate->user->name"
                            :description="($this->classNames[$candidate->id] ?? __('No class')).($candidate->currentSupervision ? ' · '.__('now: :name', ['name' => $candidate->currentSupervision->teacher->user->name]) : '')" />
                    @empty
                        <flux:text class="text-sm">{{ __('No mahasantri found.') }}</flux:text>
                    @endforelse
                </flux:checkbox.group>
                <flux:error name="students" />
                <flux:error name="supervisor" />

                <flux:button variant="primary" icon="user-plus" wire:click="assign" :disabled="$this->candidates->isEmpty()" data-test="assign-supervisor">
                    {{ __('Assign to :name', ['name' => $this->teacher->user->name]) }}
                </flux:button>
            @else
                <flux:text class="py-8 text-center">{{ __('Choose a muhadhir to see and assign their mahasantri.') }}</flux:text>
            @endif
        </div>
    </div>
</section>
