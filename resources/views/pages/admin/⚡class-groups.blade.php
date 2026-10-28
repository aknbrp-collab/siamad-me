<?php

use App\Actions\Academic\ManageClassGroups;
use App\Enums\Gender;
use App\Enums\SemesterStatus;
use App\Models\ClassEnrollment;
use App\Models\ClassGroup;
use App\Models\Semester;
use App\Models\StudyProgram;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Classes')] class extends Component {
    public ?int $editingId = null;
    public ?int $studyProgramId = null;
    /** Cohort classes: the name without the level, e.g. "Putra A". */
    public string $label = '';
    /** Classes made before v4: their fixed name. */
    public string $name = '';
    public ?int $entrySemesterId = null;
    public ?int $startLevel = 1;
    public ?string $gender = null;

    /**
     * Cohorts first (newest intake first), then the classes made before v4.
     *
     * @return Collection<int, ClassGroup>
     */
    #[Computed]
    public function classGroups(): Collection
    {
        $active = Semester::active();

        return ClassGroup::query()
            ->with(['studyProgram', 'entrySemester.academicYear', 'lastSemester.academicYear'])
            ->withCount(['classEnrollments as active_students_count' => fn ($query) => $query->where('semester_id', $active?->id)])
            ->orderByRaw('case when entry_semester_id is null then 1 else 0 end')
            ->orderBy('study_program_id')->orderBy('level')->orderBy('name')
            ->get();
    }

    /**
     * @return Collection<int, StudyProgram>
     */
    #[Computed]
    public function programs(): Collection
    {
        return StudyProgram::query()->orderBy('code')->get();
    }

    /**
     * Semesters a new class can start in.
     *
     * @return Collection<int, Semester>
     */
    #[Computed]
    public function startSemesters(): Collection
    {
        return Semester::query()->with('academicYear')->where('status', '!=', SemesterStatus::Closed)->get()
            ->sortBy(fn (Semester $semester) => $semester->ordinal())->values();
    }

    #[Computed]
    public function editing(): ?ClassGroup
    {
        return $this->editingId ? ClassGroup::query()->find($this->editingId) : null;
    }

    #[Computed]
    public function started(): bool
    {
        return $this->editingId !== null && ClassEnrollment::query()->where('class_group_id', $this->editingId)->exists();
    }

    public function create(): void
    {
        $this->reset('editingId', 'label', 'name', 'gender');
        $this->startLevel = 1;
        $this->studyProgramId = $this->programs->first()?->id;
        // Usually the next semester: the one after the active one, else the active one.
        $active = Semester::active();
        $this->entrySemesterId = $this->startSemesters->first(fn (Semester $semester) => $active && $semester->ordinal() > $active->ordinal())?->id
            ?? $this->startSemesters->first()?->id;
        $this->resetValidation();
        Flux::modal('class-form')->show();
    }

    public function edit(int $id): void
    {
        $classGroup = ClassGroup::query()->findOrFail($id);

        $this->editingId = $classGroup->id;
        $this->studyProgramId = $classGroup->study_program_id;
        $this->label = (string) $classGroup->label;
        $this->name = $classGroup->name;
        $this->entrySemesterId = $classGroup->entry_semester_id;
        $this->startLevel = $classGroup->start_level;
        $this->gender = $classGroup->gender?->value;
        unset($this->editing, $this->started);
        $this->resetValidation();
        Flux::modal('class-form')->show();
    }

    public function save(ManageClassGroups $manage): void
    {
        $this->resetErrorBag();

        try {
            $manage->save($this->editing, [
                'study_program_id' => $this->studyProgramId,
                'gender' => $this->gender,
                'label' => $this->label,
                'name' => $this->name,
                'entry_semester_id' => $this->entrySemesterId,
                'start_level' => $this->startLevel,
            ]);
        } catch (ValidationException $exception) {
            // The form's fields are named a little differently.
            $keys = ['study_program_id' => 'studyProgramId', 'entry_semester_id' => 'entrySemesterId', 'start_level' => 'startLevel'];
            throw ValidationException::withMessages(collect($exception->errors())->mapWithKeys(fn ($messages, $key) => [$keys[$key] ?? $key => $messages])->all());
        }

        unset($this->classGroups);
        Flux::modal('class-form')->close();
        Flux::toast(variant: 'success', text: __('Class saved. Past semesters keep the name they had.'));
    }

    public function delete(int $id): void
    {
        $classGroup = ClassGroup::query()->findOrFail($id);

        $active = Semester::active();

        if ($active && ClassEnrollment::query()->where('semester_id', $active->id)->where('class_group_id', $id)->exists()) {
            Flux::toast(variant: 'danger', text: __('This class has mahasantri in the active semester.'));

            return;
        }

        // Soft delete: past enrolments still point to it.
        $classGroup->delete();
        unset($this->classGroups);
        Flux::toast(variant: 'success', text: __('Class removed.'));
    }
}; ?>

<section class="w-full">
    <x-page-header :title="__('Classes')" :subtitle="__('A class is a group of mahasantri (an intake), Putra or Putri. It starts at a level and moves up every semester: Semester I, then II, III … The roman numeral changes when the next semester is activated.')">
        <x-slot:actions>
            <flux:button variant="primary" icon="plus" wire:click="create" :disabled="$this->programs->isEmpty()">{{ __('Add class') }}</flux:button>
        </x-slot:actions>
    </x-page-header>

    @if ($this->programs->isEmpty())
        <flux:callout icon="information-circle" class="mb-6">
            <flux:callout.text>
                {{ __('Add a study program first.') }}
                <flux:link :href="route('admin.study-programs')" wire:navigate>{{ __('Study programs') }}</flux:link>
            </flux:callout.text>
        </flux:callout>
    @endif

    @if ($this->classGroups->whereNull('gender')->isNotEmpty())
        <flux:callout icon="exclamation-triangle" color="amber" class="mb-6" data-test="classes-without-gender"
            :text="__('Some classes have no Putra/Putri yet. Set it before placing mahasantri in them.')" />
    @endif

    <div class="overflow-x-auto">
        <flux:table class="table-stack">
            <flux:table.columns>
                <flux:table.column>{{ __('Class') }}</flux:table.column>
                <flux:table.column>{{ __('Semester level') }}</flux:table.column>
                <flux:table.column class="hidden md:table-cell">{{ __('Started') }}</flux:table.column>
                <flux:table.column>{{ __('Putra / Putri') }}</flux:table.column>
                <flux:table.column class="hidden sm:table-cell">{{ __('Study program') }}</flux:table.column>
                <flux:table.column class="hidden sm:table-cell">{{ __('Mahasantri (active semester)') }}</flux:table.column>
                <flux:table.column></flux:table.column>
            </flux:table.columns>
            <flux:table.rows>
                @forelse ($this->classGroups as $classGroup)
                    <flux:table.row :key="$classGroup->id">
                        <flux:table.cell class="font-medium">
                            {{ $classGroup->name }}
                            @unless ($classGroup->isCohort())
                                <flux:badge size="sm" class="ms-1">{{ __('Until :semester', ['semester' => $classGroup->lastSemester?->label() ?? '—']) }}</flux:badge>
                            @endunless
                        </flux:table.cell>
                        <flux:table.cell>{{ $classGroup->levelLabel() }}</flux:table.cell>
                        <flux:table.cell class="hidden md:table-cell">
                            @if ($classGroup->isCohort())
                                {{ $classGroup->entrySemester?->label() }} · {{ ClassGroup::levelName((int) $classGroup->start_level) }}
                            @else
                                <span class="text-zinc-500">{{ __('Before classes moved up') }}</span>
                            @endif
                        </flux:table.cell>
                        <flux:table.cell>
                            @if ($classGroup->gender)
                                {{ $classGroup->gender->classLabel() }}
                            @else
                                <flux:badge size="sm" color="amber">{{ __('Not set') }}</flux:badge>
                            @endif
                        </flux:table.cell>
                        <flux:table.cell class="hidden sm:table-cell">{{ $classGroup->studyProgram->name }}</flux:table.cell>
                        <flux:table.cell class="hidden sm:table-cell">{{ $classGroup->active_students_count }}</flux:table.cell>
                        <flux:table.cell align="end">
                            <flux:button size="sm" variant="ghost" icon="pencil-square" wire:click="edit({{ $classGroup->id }})" :aria-label="__('Edit')" />
                            <flux:button size="sm" variant="ghost" icon="trash" wire:click="delete({{ $classGroup->id }})" wire:confirm="{{ __('Remove this class?') }}" :aria-label="__('Remove')" />
                        </flux:table.cell>
                    </flux:table.row>
                @empty
                    <flux:table.row>
                        <flux:table.cell colspan="7" class="text-center">{{ __('No classes yet.') }}</flux:table.cell>
                    </flux:table.row>
                @endforelse
            </flux:table.rows>
        </flux:table>
    </div>

    <flux:modal name="class-form" class="w-full max-w-md">
        <form wire:submit="save" class="space-y-6">
            <flux:heading size="lg">{{ $editingId ? __('Edit class') : __('Add class') }}</flux:heading>
            <flux:select wire:model="studyProgramId" :label="__('Study program')" :placeholder="__('Choose…')" required>
                @foreach ($this->programs as $program)
                    <flux:select.option :value="$program->id">{{ $program->code }} — {{ $program->name }}</flux:select.option>
                @endforeach
            </flux:select>
            @if ($this->editing && ! $this->editing->isCohort())
                <flux:input wire:model="name" :label="__('Name')" required />
                <flux:text class="text-sm">{{ __('This class was made before classes moved up: it keeps its level and can be used until :semester.', ['semester' => $this->editing->lastSemester?->label() ?? '—']) }}</flux:text>
            @else
                <flux:input wire:model="label" :label="__('Name')" placeholder="Putra A" required
                    :description:trailing="__('Without the level: SIAKAD adds it, e.g. Semester I Putra A.')" />
                <div class="grid gap-6 sm:grid-cols-2">
                    <flux:select wire:model="entrySemesterId" :label="__('First semester')" :placeholder="__('Choose…')" :disabled="$this->started" required>
                        @foreach ($this->startSemesters as $semester)
                            <flux:select.option :value="$semester->id">{{ $semester->label() }}</flux:select.option>
                        @endforeach
                    </flux:select>
                    <flux:select wire:model="startLevel" :label="__('Starting level')" :disabled="$this->started" required>
                        @foreach (range(1, ClassGroup::MAX_LEVEL) as $option)
                            <flux:select.option :value="$option">{{ ClassGroup::levelName($option) }}</flux:select.option>
                        @endforeach
                    </flux:select>
                </div>
                @if ($this->started)
                    <flux:text class="text-sm">{{ __('Mahasantri are already placed in this class, so where it starts can no longer change.') }}</flux:text>
                @endif
            @endif
            <div class="grid gap-6 sm:grid-cols-2">
                <flux:select wire:model="gender" :label="__('Putra / Putri')" :placeholder="__('Choose…')" required>
                    @foreach (Gender::cases() as $case)
                        <flux:select.option :value="$case->value">{{ $case->classLabel() }}</flux:select.option>
                    @endforeach
                </flux:select>
            </div>
            <div class="flex justify-end gap-2">
                <flux:modal.close><flux:button variant="ghost">{{ __('Cancel') }}</flux:button></flux:modal.close>
                <flux:button type="submit" variant="primary">{{ __('Save') }}</flux:button>
            </div>
        </form>
    </flux:modal>
</section>
