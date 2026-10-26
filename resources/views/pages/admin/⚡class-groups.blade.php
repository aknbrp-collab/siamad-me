<?php

use App\Enums\Gender;
use App\Models\ClassEnrollment;
use App\Models\ClassGroup;
use App\Models\Semester;
use App\Models\StudyProgram;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Classes')] class extends Component {
    public ?int $editingId = null;
    public ?int $studyProgramId = null;
    public string $name = '';
    public ?int $level = 1;
    public ?string $gender = null;

    /**
     * @return Collection<int, ClassGroup>
     */
    #[Computed]
    public function classGroups(): Collection
    {
        $active = Semester::active();

        return ClassGroup::query()
            ->with('studyProgram')
            ->withCount(['classEnrollments as active_students_count' => fn ($query) => $query->where('semester_id', $active?->id)])
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

    public function create(): void
    {
        $this->reset('editingId', 'name', 'gender');
        $this->level = 1;
        $this->studyProgramId = $this->programs->first()?->id;
        $this->resetValidation();
        Flux::modal('class-form')->show();
    }

    public function edit(int $id): void
    {
        $classGroup = ClassGroup::query()->findOrFail($id);

        $this->editingId = $classGroup->id;
        $this->studyProgramId = $classGroup->study_program_id;
        $this->name = $classGroup->name;
        $this->level = $classGroup->level;
        $this->gender = $classGroup->gender?->value;
        $this->resetValidation();
        Flux::modal('class-form')->show();
    }

    public function save(): void
    {
        $data = $this->validate([
            'studyProgramId' => ['required', 'integer', Rule::exists('study_programs', 'id')->whereNull('deleted_at')],
            'name' => [
                'required', 'string', 'max:255',
                Rule::unique('class_groups', 'name')
                    ->where('study_program_id', $this->studyProgramId)
                    ->whereNull('deleted_at')
                    ->ignore($this->editingId),
            ],
            'level' => ['required', 'integer', 'between:1,'.ClassGroup::MAX_LEVEL],
            'gender' => ['required', Rule::enum(Gender::class)],
        ], [
            'gender.required' => __('Choose Putra or Putri.'),
        ]);

        // Changing the gender of a class holding the other gender this semester would make it mixed.
        if ($this->editingId && ($active = Semester::active())) {
            $mismatch = ClassEnrollment::query()
                ->where('semester_id', $active->id)
                ->where('class_group_id', $this->editingId)
                ->whereHas('student', fn ($query) => $query->where('gender', '!=', $data['gender']))
                ->exists();

            if ($mismatch) {
                $this->addError('gender', __('This class has mahasantri of the other gender in the active semester.'));

                return;
            }
        }

        ClassGroup::query()->updateOrCreate(['id' => $this->editingId], [
            'study_program_id' => $data['studyProgramId'],
            'name' => $data['name'],
            'level' => $data['level'],
            'gender' => $data['gender'],
        ]);

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
        Flux::toast(variant: 'success', text: __('Class removed.'));
    }
}; ?>

<section class="w-full">
    <x-page-header :title="__('Classes')" :subtitle="__('Classes per semester of study (Semester I, II, III …), Putra or Putri. Mahasantri are placed in one class each semester and move up one level every semester.')">
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
                <flux:table.column>{{ __('Putra / Putri') }}</flux:table.column>
                <flux:table.column class="hidden sm:table-cell">{{ __('Study program') }}</flux:table.column>
                <flux:table.column class="hidden sm:table-cell">{{ __('Mahasantri (active semester)') }}</flux:table.column>
                <flux:table.column></flux:table.column>
            </flux:table.columns>
            <flux:table.rows>
                @forelse ($this->classGroups as $classGroup)
                    <flux:table.row :key="$classGroup->id">
                        <flux:table.cell class="font-medium">{{ $classGroup->name }}</flux:table.cell>
                        <flux:table.cell>{{ $classGroup->levelLabel() }}</flux:table.cell>
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
                        <flux:table.cell colspan="6" class="text-center">{{ __('No classes yet.') }}</flux:table.cell>
                    </flux:table.row>
                @endforelse
            </flux:table.rows>
        </flux:table>
    </div>

    <flux:modal name="class-form" class="w-full max-w-md">
        <form wire:submit="save" class="space-y-6">
            <flux:heading size="lg">{{ $editingId ? __('Edit class') : __('Add class') }}</flux:heading>
            <flux:select wire:model="studyProgramId" :label="__('Study program')" required>
                @foreach ($this->programs as $program)
                    <flux:select.option :value="$program->id">{{ $program->code }} — {{ $program->name }}</flux:select.option>
                @endforeach
            </flux:select>
            <flux:input wire:model="name" :label="__('Name')" placeholder="Semester I Putra A" required />
            <div class="grid gap-6 sm:grid-cols-2">
                <flux:select wire:model="level" :label="__('Semester level')" required>
                    @foreach (range(1, ClassGroup::MAX_LEVEL) as $option)
                        <flux:select.option :value="$option">{{ ClassGroup::levelName($option) }}</flux:select.option>
                    @endforeach
                </flux:select>
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
