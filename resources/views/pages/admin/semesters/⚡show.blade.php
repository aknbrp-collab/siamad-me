<?php

use App\Actions\Enrollment\ManageClassEnrollments;
use App\Actions\Enrollment\ManageCourseOfferings;
use App\Enums\SemesterTerm;
use App\Enums\StudentStatus;
use App\Enums\TeacherStatus;
use App\Models\ClassEnrollment;
use App\Models\ClassGroup;
use App\Models\CourseOffering;
use App\Models\Semester;
use App\Models\Student;
use App\Models\Subject;
use App\Models\Teacher;
use Flux\Flux;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

new #[Title('Semester')] class extends Component {
    public Semester $semester;

    #[Url]
    public string $tab = 'classes';

    #[Url(as: 'class')]
    public ?int $selectedClassId = null;

    public string $studentSearch = '';

    public ?int $copyFromId = null;

    /** @var array<int|string, int|string|null> */
    public array $classMap = [];

    public ?int $copyOfferingsFromId = null;

    // Course offering form (snake_case to match the action's validation keys).
    public ?int $offeringId = null;
    public ?int $subject_id = null;
    public ?int $class_group_id = null;
    public ?int $teacher_id = null;
    public ?int $day_of_week = null;
    public ?string $starts_at = null;
    public ?string $ends_at = null;
    public ?string $room = null;

    public function mount(Semester $semester): void
    {
        $this->semester = $semester->load('academicYear');
    }

    #[Computed]
    public function locked(): bool
    {
        return $this->semester->isLocked();
    }

    /**
     * Classes that exist now, plus removed ones still holding this semester's students.
     *
     * @return Collection<int, ClassGroup>
     */
    #[Computed]
    public function classGroups(): Collection
    {
        return ClassGroup::withTrashed()
            ->with('studyProgram')
            ->withCount(['classEnrollments as students_count' => fn ($query) => $query->where('semester_id', $this->semester->id)])
            ->where(fn (Builder $query) => $query
                ->whereNull('deleted_at')
                ->orWhereHas('classEnrollments', fn ($query) => $query->where('semester_id', $this->semester->id)))
            ->orderBy('level')->orderBy('name')
            ->get();
    }

    #[Computed]
    public function selectedClass(): ?ClassGroup
    {
        return $this->classGroups->firstWhere('id', $this->selectedClassId);
    }

    /**
     * @return Collection<int, ClassEnrollment>
     */
    #[Computed]
    public function roster(): Collection
    {
        if ($this->selectedClassId === null) {
            return new Collection;
        }

        return ClassEnrollment::query()
            ->with('student.user')
            ->where('semester_id', $this->semester->id)
            ->where('class_group_id', $this->selectedClassId)
            ->get()
            ->sortBy('student.user.name')
            ->values();
    }

    /**
     * Active students matching the search, with their class this semester (if any).
     *
     * @return Collection<int, Student>
     */
    #[Computed]
    public function candidates(): Collection
    {
        if (mb_strlen(trim($this->studentSearch)) < 2) {
            return new Collection;
        }

        $term = '%'.mb_strtolower(trim($this->studentSearch)).'%';

        return Student::query()
            ->with(['user', 'classEnrollments' => fn ($query) => $query->where('semester_id', $this->semester->id)])
            ->where('status', StudentStatus::Active)
            // Putra classes only list putra, putri classes only putri.
            ->when($this->selectedClass?->gender, fn (Builder $query, $gender) => $query->where('gender', $gender))
            ->where(fn (Builder $query) => $query
                ->whereRaw('lower(nim) like ?', [$term])
                ->orWhereHas('user', fn (Builder $query) => $query->whereRaw('lower(name) like ?', [$term])))
            ->orderBy('nim')
            ->limit(15)
            ->get();
    }

    /**
     * The semester of study each candidate should be in now, by student id.
     *
     * @return array<int, int>
     */
    #[Computed]
    public function candidateLevels(): array
    {
        return app(ManageClassEnrollments::class)->expectedLevels($this->semester, $this->candidates->modelKeys());
    }

    /**
     * @return Collection<int, Semester>
     */
    #[Computed]
    public function otherSemesters(): Collection
    {
        return Semester::query()
            ->with('academicYear')
            ->whereKeyNot($this->semester->id)
            ->orderByDesc('academic_year_id')->orderByDesc('term')
            ->get();
    }

    /**
     * Classes used in the semester we copy rosters from.
     *
     * @return Collection<int, ClassGroup>
     */
    #[Computed]
    public function sourceClasses(): Collection
    {
        if ($this->copyFromId === null) {
            return new Collection;
        }

        return ClassGroup::withTrashed()
            ->withCount(['classEnrollments as students_count' => fn ($query) => $query->where('semester_id', $this->copyFromId)])
            ->whereHas('classEnrollments', fn ($query) => $query->where('semester_id', $this->copyFromId))
            ->orderBy('level')->orderBy('name')
            ->get();
    }

    /**
     * @return Collection<int, CourseOffering>
     */
    #[Computed]
    public function offerings(): Collection
    {
        return CourseOffering::query()
            ->with(['classGroup', 'teacher.user'])
            ->withCount('enrollments')
            ->where('semester_id', $this->semester->id)
            ->orderBy('class_group_id')->orderBy('subject_code')
            ->get();
    }

    /**
     * @return Collection<int, Subject>
     */
    #[Computed]
    public function subjects(): Collection
    {
        return Subject::query()->where('is_active', true)->orderBy('code')->get();
    }

    /**
     * @return Collection<int, Teacher>
     */
    #[Computed]
    public function teachers(): Collection
    {
        return Teacher::query()->with('user')->where('status', TeacherStatus::Active)->get()->sortBy('user.name')->values();
    }

    public function selectClass(int $classGroupId): void
    {
        $this->selectedClassId = $classGroupId;
        $this->studentSearch = '';
    }

    public function enroll(int $studentId, ManageClassEnrollments $manage): void
    {
        $this->resetErrorBag();

        $classGroup = ClassGroup::query()->findOrFail($this->selectedClassId);

        $manage->enroll($this->semester, $classGroup, [$studentId]);

        unset($this->candidates, $this->roster, $this->classGroups);
        Flux::toast(variant: 'success', text: __('Placed in :class.', ['class' => $classGroup->name]));
    }

    public function removeEnrollment(int $enrollmentId, ManageClassEnrollments $manage): void
    {
        $this->resetErrorBag();

        $enrollment = ClassEnrollment::query()->where('semester_id', $this->semester->id)->findOrFail($enrollmentId);

        $manage->remove($enrollment);

        unset($this->roster, $this->classGroups);
        Flux::toast(variant: 'success', text: __('Removed from the class.'));
    }

    public function openCopy(): void
    {
        $this->copyFromId = $this->otherSemesters->first()?->id;
        $this->buildClassMap();
        $this->resetValidation();
        Flux::modal('copy-enrollments')->show();
    }

    public function updatedCopyFromId(): void
    {
        $this->buildClassMap();
    }

    /**
     * Default mapping: mahasantri move up one semester of study every
     * semester, into the class one level up in the same study program with
     * the same gender (Semester II Putra -> Semester III Putra). Copying from
     * a later semester keeps the same level. Every pair can be changed.
     */
    private function buildClassMap(): void
    {
        unset($this->sourceClasses);

        $current = ClassGroup::query()->orderBy('name')->get();
        $from = $this->otherSemesters->firstWhere('id', $this->copyFromId);
        $step = $from && $from->chronology() < $this->semester->chronology() ? 1 : 0;

        $this->classMap = [];

        foreach ($this->sourceClasses as $source) {
            $target = $current->first(fn (ClassGroup $class) => $class->study_program_id === $source->study_program_id
                && $class->level === $source->level + $step
                && $source->gender !== null
                && $class->gender === $source->gender);

            $this->classMap[$source->id] = $target?->id;
        }
    }

    public function copyEnrollments(ManageClassEnrollments $manage): void
    {
        $this->resetErrorBag();

        $from = Semester::query()->findOrFail($this->copyFromId);

        $result = $manage->copy($from, $this->semester, $this->classMap);

        Flux::modal('copy-enrollments')->close();
        unset($this->classGroups, $this->roster);
        Flux::toast(
            variant: $result['skipped'] > 0 ? 'warning' : 'success',
            text: trans_choice(':count mahasantri placed.|:count mahasantri placed.', $result['placed'])
                .($result['skipped'] > 0 ? ' '.__(':count skipped: their gender does not match the class. Place them by hand.', ['count' => $result['skipped']]) : ''),
        );
    }

    public function createOffering(): void
    {
        $this->reset('offeringId', 'subject_id', 'teacher_id', 'day_of_week', 'starts_at', 'ends_at', 'room');
        $this->class_group_id = $this->selectedClassId;
        $this->resetValidation();
        Flux::modal('offering-form')->show();
    }

    public function editOffering(int $id): void
    {
        $offering = CourseOffering::query()->where('semester_id', $this->semester->id)->findOrFail($id);

        $this->offeringId = $offering->id;
        $this->subject_id = $offering->subject_id;
        $this->class_group_id = $offering->class_group_id;
        $this->teacher_id = $offering->teacher_id;
        $this->day_of_week = $offering->day_of_week;
        $this->starts_at = $offering->starts_at ? substr($offering->starts_at, 0, 5) : null;
        $this->ends_at = $offering->ends_at ? substr($offering->ends_at, 0, 5) : null;
        $this->room = $offering->room;
        $this->resetValidation();
        Flux::modal('offering-form')->show();
    }

    public function saveOffering(ManageCourseOfferings $manage): void
    {
        $this->resetErrorBag();

        $offering = $this->offeringId
            ? CourseOffering::query()->where('semester_id', $this->semester->id)->findOrFail($this->offeringId)
            : null;

        $manage->save($this->semester, $offering, [
            'subject_id' => $this->subject_id,
            'class_group_id' => $this->class_group_id,
            'teacher_id' => $this->teacher_id,
            'day_of_week' => $this->day_of_week,
            'starts_at' => $this->starts_at ?: null,
            'ends_at' => $this->ends_at ?: null,
            'room' => $this->room ?: null,
        ]);

        Flux::modal('offering-form')->close();
        unset($this->offerings);
        Flux::toast(variant: 'success', text: __('Course offering saved.'));
    }

    public function deleteOffering(int $id, ManageCourseOfferings $manage): void
    {
        $this->resetErrorBag();

        $manage->delete(CourseOffering::query()->where('semester_id', $this->semester->id)->findOrFail($id));

        unset($this->offerings);
        Flux::toast(variant: 'success', text: __('Course offering removed.'));
    }

    public function openCopyOfferings(): void
    {
        $this->copyOfferingsFromId = $this->otherSemesters->first(fn (Semester $s) => $s->term === $this->semester->term)?->id
            ?? $this->otherSemesters->first()?->id;
        $this->resetValidation();
        Flux::modal('copy-offerings')->show();
    }

    public function copyOfferings(ManageCourseOfferings $manage): void
    {
        $this->resetErrorBag();

        $result = $manage->copy(Semester::query()->findOrFail($this->copyOfferingsFromId), $this->semester);

        Flux::modal('copy-offerings')->close();
        unset($this->offerings);
        Flux::toast(
            variant: $result['clashes'] > 0 ? 'warning' : 'success',
            text: __(':copied offerings copied, :skipped skipped.', $result)
                .($result['clashes'] > 0 ? ' '.__(':clashes not copied because their time clashes with this semester\'s schedule; add them by hand.', $result) : ''),
        );
    }
}; ?>

<section class="w-full">
    <x-page-header :title="$semester->label()">
        <x-slot:actions>
            <flux:badge :color="$semester->status->color()" size="lg">{{ $semester->status->label() }}</flux:badge>
            <flux:button size="sm" variant="ghost" icon="arrow-left" :href="route('admin.academic-years')" wire:navigate>{{ __('Academic years') }}</flux:button>
        </x-slot:actions>
    </x-page-header>

    @if ($this->locked)
        <flux:callout icon="lock-closed" color="amber" class="mb-6"
            :heading="__('Archive — read only')"
            :text="__('This semester is closed. Its classes and offerings are kept exactly as they were.')" />
    @endif

    @foreach (['semester', 'enrollment', 'offering'] as $key)
        @error($key)
            <flux:callout variant="danger" icon="exclamation-triangle" class="mb-6" :text="$message" />
        @enderror
    @endforeach

    <flux:radio.group wire:model.live="tab" variant="segmented" class="mb-6 w-full sm:w-auto">
        <flux:radio value="classes" :label="__('Classes')" icon="user-group" />
        <flux:radio value="offerings" :label="__('Course offerings')" icon="book-open" />
    </flux:radio.group>

    @if ($tab === 'classes')
        <div class="grid gap-6 lg:grid-cols-[18rem_1fr]">
            <div class="space-y-3">
                @unless ($this->locked)
                    <flux:button class="w-full" icon="document-duplicate" wire:click="openCopy" :disabled="$this->otherSemesters->isEmpty()">
                        {{ __('Copy from another semester') }}
                    </flux:button>
                @endunless

                <div class="flex flex-col gap-1">
                    @forelse ($this->classGroups as $classGroup)
                        <button
                            type="button"
                            wire:key="class-{{ $classGroup->id }}"
                            wire:click="selectClass({{ $classGroup->id }})"
                            @class([
                                'flex items-center justify-between rounded-lg px-3 py-2 text-start text-sm transition',
                                'bg-zinc-100 font-medium dark:bg-zinc-700' => $selectedClassId === $classGroup->id,
                                'hover:bg-zinc-50 dark:hover:bg-zinc-800' => $selectedClassId !== $classGroup->id,
                            ])
                        >
                            <span>
                                {{ $classGroup->name }}
                                @if ($classGroup->trashed())
                                    <span class="text-xs text-zinc-500">({{ __('removed') }})</span>
                                @endif
                                <span class="block text-xs text-zinc-500">{{ $classGroup->description() }}</span>
                            </span>
                            <flux:badge size="sm">{{ $classGroup->students_count }}</flux:badge>
                        </button>
                    @empty
                        <flux:text class="text-sm">
                            {{ __('No classes yet.') }}
                            <flux:link :href="route('admin.class-groups')" wire:navigate>{{ __('Add classes') }}</flux:link>
                        </flux:text>
                    @endforelse
                </div>
            </div>

            <div>
                @if ($this->selectedClass)
                    <flux:heading size="lg">{{ $this->selectedClass->name }} · {{ trans_choice(':count mahasantri|:count mahasantri', $this->roster->count()) }}</flux:heading>
                    <flux:text class="mb-4 text-sm">{{ $this->selectedClass->description() }}</flux:text>

                    @if (! $this->locked && $this->selectedClass->gender === null)
                        <flux:callout icon="exclamation-triangle" color="amber" class="mb-4" data-test="class-gender-missing"
                            :text="__('Set Putra or Putri for :classes first (Classes page).', ['classes' => $this->selectedClass->name])">
                            <x-slot name="actions">
                                <flux:button size="sm" :href="route('admin.class-groups')" wire:navigate>{{ __('Classes') }}</flux:button>
                            </x-slot>
                        </flux:callout>
                    @endif

                    @if (! $this->locked && $this->selectedClass->gender !== null)
                        <flux:input
                            wire:model.live.debounce.300ms="studentSearch"
                            icon="magnifying-glass"
                            :placeholder="__('Add mahasantri: type a NIM or name…')"
                            class="mb-2"
                        />
                        @if ($this->candidates->isNotEmpty())
                            <div class="mb-6 divide-y divide-zinc-200 rounded-lg border border-zinc-200 dark:divide-zinc-700 dark:border-zinc-700">
                                @foreach ($this->candidates as $candidate)
                                    @php($current = $candidate->classEnrollments->first())
                                    <div wire:key="candidate-{{ $candidate->id }}" class="flex items-center justify-between gap-3 px-3 py-2 text-sm">
                                        <div>
                                            <span class="font-mono text-zinc-500">{{ $candidate->nim }}</span>
                                            <span class="ms-2 font-medium">{{ $candidate->user->name }}</span>
                                            @if ($current)
                                                <span class="ms-2 text-zinc-500">· {{ $current->class_name }}</span>
                                            @endif
                                            @php($expected = $this->candidateLevels[$candidate->id] ?? 1)
                                            @if ($expected !== $this->selectedClass->level)
                                                {{-- Not blocking: repeating or skipping a level is the office's call. --}}
                                                <flux:badge size="sm" color="amber" class="ms-2">{{ __('Expected: :level', ['level' => ClassGroup::levelName($expected)]) }}</flux:badge>
                                            @endif
                                        </div>
                                        @if ($current?->class_group_id === $selectedClassId)
                                            <flux:badge size="sm" color="green">{{ __('In this class') }}</flux:badge>
                                        @else
                                            <flux:button size="xs" wire:click="enroll({{ $candidate->id }})">{{ $current ? __('Move here') : __('Add') }}</flux:button>
                                        @endif
                                    </div>
                                @endforeach
                            </div>
                        @elseif (mb_strlen(trim($studentSearch)) >= 2)
                            <flux:text class="mb-6 text-sm">{{ __('No active mahasantri found.') }}</flux:text>
                        @endif
                    @endif

                    <div class="overflow-x-auto">
                        <flux:table class="table-stack">
                            <flux:table.columns>
                                <flux:table.column>{{ __('NIM') }}</flux:table.column>
                                <flux:table.column>{{ __('Name') }}</flux:table.column>
                                <flux:table.column></flux:table.column>
                            </flux:table.columns>
                            <flux:table.rows>
                                @forelse ($this->roster as $enrollment)
                                    <flux:table.row :key="$enrollment->id">
                                        <flux:table.cell class="font-mono">{{ $enrollment->student->nim }}</flux:table.cell>
                                        <flux:table.cell class="font-medium">{{ $enrollment->student->user->name }}</flux:table.cell>
                                        <flux:table.cell align="end">
                                            @unless ($this->locked)
                                                <flux:button size="sm" variant="ghost" icon="x-mark"
                                                    wire:click="removeEnrollment({{ $enrollment->id }})"
                                                    wire:confirm="{{ __('Remove :name from this class?', ['name' => $enrollment->student->user->name]) }}"
                                                    :aria-label="__('Remove')" />
                                            @endunless
                                        </flux:table.cell>
                                    </flux:table.row>
                                @empty
                                    <flux:table.row>
                                        <flux:table.cell colspan="3" class="text-center">{{ __('No mahasantri in this class yet.') }}</flux:table.cell>
                                    </flux:table.row>
                                @endforelse
                            </flux:table.rows>
                        </flux:table>
                    </div>
                @else
                    <flux:text class="py-8 text-center">{{ __('Choose a class to see and edit its mahasantri.') }}</flux:text>
                @endif
            </div>
        </div>
    @else
        @unless ($this->locked)
            <div class="mb-4 flex flex-wrap gap-2">
                <flux:button variant="primary" icon="plus" wire:click="createOffering" :disabled="$this->subjects->isEmpty() || $this->teachers->isEmpty()">{{ __('Add offering') }}</flux:button>
                <flux:button icon="document-duplicate" wire:click="openCopyOfferings" :disabled="$this->otherSemesters->isEmpty()">{{ __('Copy from another semester') }}</flux:button>
            </div>
            @if ($this->subjects->isEmpty() || $this->teachers->isEmpty())
                <flux:callout icon="information-circle" class="mb-4" :text="__('Offerings need at least one active subject and one active ustadz.')" />
            @endif
        @endunless

        <div class="overflow-x-auto">
            <flux:table class="table-stack">
                <flux:table.columns>
                    <flux:table.column>{{ __('Subject') }}</flux:table.column>
                    <flux:table.column>{{ __('Class') }}</flux:table.column>
                    <flux:table.column class="hidden md:table-cell">{{ __('Ustadz') }}</flux:table.column>
                    <flux:table.column class="hidden lg:table-cell">{{ __('Schedule') }}</flux:table.column>
                    <flux:table.column class="hidden sm:table-cell">{{ __('Students') }}</flux:table.column>
                    <flux:table.column></flux:table.column>
                </flux:table.columns>
                <flux:table.rows>
                    @forelse ($this->offerings as $offering)
                        <flux:table.row :key="$offering->id">
                            <flux:table.cell>
                                <span class="font-mono text-zinc-500">{{ $offering->subject_code }}</span>
                                <span class="ms-1 font-medium">{{ $offering->subject_name }}</span>
                                <span class="text-zinc-500">· {{ $offering->credits }} {{ __('SKS') }}</span>
                            </flux:table.cell>
                            <flux:table.cell>{{ $offering->classGroup->name }}</flux:table.cell>
                            <flux:table.cell class="hidden md:table-cell">{{ $offering->teacher->displayName() }}</flux:table.cell>
                            <flux:table.cell class="hidden lg:table-cell">{{ $offering->scheduleLabel() }}</flux:table.cell>
                            <flux:table.cell class="hidden sm:table-cell">{{ $offering->enrollments_count }}</flux:table.cell>
                            <flux:table.cell align="end">
                                @unless ($this->locked)
                                    <flux:button size="sm" variant="ghost" icon="pencil-square" wire:click="editOffering({{ $offering->id }})" :aria-label="__('Edit')" />
                                    <flux:button size="sm" variant="ghost" icon="trash" wire:click="deleteOffering({{ $offering->id }})" wire:confirm="{{ __('Remove this course offering?') }}" :aria-label="__('Remove')" />
                                @endunless
                            </flux:table.cell>
                        </flux:table.row>
                    @empty
                        <flux:table.row>
                            <flux:table.cell colspan="6" class="text-center">{{ __('No course offerings yet.') }}</flux:table.cell>
                        </flux:table.row>
                    @endforelse
                </flux:table.rows>
            </flux:table>
        </div>
    @endif

    <flux:modal name="copy-enrollments" class="w-full max-w-xl">
        <form wire:submit="copyEnrollments" class="space-y-6">
            <div>
                <flux:heading size="lg">{{ __('Copy classes from another semester') }}</flux:heading>
                <flux:text class="mt-1">{{ __('Active mahasantri are placed in the class you choose. Graduated, dropped-out and on-leave students and anyone already placed this semester are skipped.') }}</flux:text>
                <flux:text class="mt-1">{{ __('By default everyone moves up one semester, into the class of the same gender one level up. Mahasantri whose gender does not match the chosen class are skipped.') }}</flux:text>
            </div>

            <flux:select wire:model.live="copyFromId" :label="__('Copy from')">
                @foreach ($this->otherSemesters as $other)
                    <flux:select.option :value="$other->id">{{ $other->label() }}</flux:select.option>
                @endforeach
            </flux:select>

            @if ($this->sourceClasses->isEmpty())
                <flux:text>{{ __('That semester has no class enrolments.') }}</flux:text>
            @else
                <div class="space-y-3">
                    @foreach ($this->sourceClasses as $source)
                        <div wire:key="map-{{ $source->id }}" class="grid items-center gap-2 sm:grid-cols-[1fr_auto_1fr]">
                            <span class="text-sm font-medium">{{ $source->name }} <span class="text-zinc-500">({{ $source->students_count }})</span>
                                <span class="block text-xs font-normal text-zinc-500">{{ $source->description() }}</span></span>
                            <flux:icon.arrow-right class="hidden size-4 text-zinc-400 sm:block" />
                            <flux:select wire:model="classMap.{{ $source->id }}" size="sm">
                                <flux:select.option value="">{{ __('— skip —') }}</flux:select.option>
                                @foreach ($this->classGroups->whereNull('deleted_at') as $target)
                                    <flux:select.option :value="$target->id">{{ $target->name }} — {{ $target->description() }}</flux:select.option>
                                @endforeach
                            </flux:select>
                        </div>
                    @endforeach
                </div>
            @endif

            @error('copyFrom')
                <flux:callout variant="danger" icon="exclamation-triangle" :text="$message" />
            @enderror

            <div class="flex justify-end gap-2">
                <flux:modal.close><flux:button variant="ghost">{{ __('Cancel') }}</flux:button></flux:modal.close>
                <flux:button type="submit" variant="primary" :disabled="$this->sourceClasses->isEmpty()">{{ __('Copy') }}</flux:button>
            </div>
        </form>
    </flux:modal>

    <flux:modal name="copy-offerings" class="w-full max-w-md">
        <form wire:submit="copyOfferings" class="space-y-6">
            <div>
                <flux:heading size="lg">{{ __('Copy course offerings') }}</flux:heading>
                <flux:text class="mt-1">{{ __('Subjects, classes, ustadz and schedules are copied. Inactive subjects or ustadz, removed classes and offerings that already exist are skipped.') }}</flux:text>
            </div>
            <flux:select wire:model="copyOfferingsFromId" :label="__('Copy from')">
                @foreach ($this->otherSemesters as $other)
                    <flux:select.option :value="$other->id">{{ $other->label() }}</flux:select.option>
                @endforeach
            </flux:select>
            @error('copyOfferingsFrom')
                <flux:callout variant="danger" icon="exclamation-triangle" :text="$message" />
            @enderror
            <div class="flex justify-end gap-2">
                <flux:modal.close><flux:button variant="ghost">{{ __('Cancel') }}</flux:button></flux:modal.close>
                <flux:button type="submit" variant="primary">{{ __('Copy') }}</flux:button>
            </div>
        </form>
    </flux:modal>

    <flux:modal name="offering-form" class="w-full max-w-lg">
        <form wire:submit="saveOffering" class="space-y-6">
            <flux:heading size="lg">{{ $offeringId ? __('Edit course offering') : __('Add course offering') }}</flux:heading>

            <flux:select wire:model="subject_id" :label="__('Subject')" :placeholder="__('Choose a subject…')" required>
                @foreach ($this->subjects as $subject)
                    <flux:select.option :value="$subject->id">{{ $subject->code }} — {{ $subject->name }} ({{ $subject->credits }} {{ __('SKS') }})</flux:select.option>
                @endforeach
            </flux:select>

            <div class="grid gap-6 sm:grid-cols-2">
                <flux:select wire:model="class_group_id" :label="__('Class')" :placeholder="__('Choose a class…')" required>
                    @foreach ($this->classGroups->whereNull('deleted_at') as $classGroup)
                        <flux:select.option :value="$classGroup->id">{{ $classGroup->name }}</flux:select.option>
                    @endforeach
                </flux:select>

                <flux:select wire:model="teacher_id" :label="__('Ustadz')" :placeholder="__('Choose an ustadz…')" required>
                    @foreach ($this->teachers as $teacher)
                        <flux:select.option :value="$teacher->id">{{ $teacher->displayName() }}</flux:select.option>
                    @endforeach
                </flux:select>
            </div>

            <div class="grid gap-6 sm:grid-cols-3">
                <flux:select wire:model="day_of_week" :label="__('Day')" :placeholder="__('—')">
                    @foreach (\App\Models\CourseOffering::days() as $number => $day)
                        <flux:select.option :value="$number">{{ $day }}</flux:select.option>
                    @endforeach
                </flux:select>
                <flux:input wire:model="starts_at" :label="__('Starts')" type="time" />
                <flux:input wire:model="ends_at" :label="__('Ends')" type="time" />
            </div>

            <flux:input wire:model="room" :label="__('Room')" />

            <div class="flex justify-end gap-2">
                <flux:modal.close><flux:button variant="ghost">{{ __('Cancel') }}</flux:button></flux:modal.close>
                <flux:button type="submit" variant="primary">{{ __('Save') }}</flux:button>
            </div>
        </form>
    </flux:modal>
</section>
