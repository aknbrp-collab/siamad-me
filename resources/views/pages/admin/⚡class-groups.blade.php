<?php

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
        $this->reset('editingId', 'name');
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
            'level' => ['required', 'integer', 'between:1,12'],
        ]);

        ClassGroup::query()->updateOrCreate(['id' => $this->editingId], [
            'study_program_id' => $data['studyProgramId'],
            'name' => $data['name'],
            'level' => $data['level'],
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
    <x-page-header :title="__('Classes')" :subtitle="__('Academic classes (kelas) by level (marhalah). Students are placed in a class per semester.')">
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

    <div class="overflow-x-auto">
        <flux:table class="table-stack">
            <flux:table.columns>
                <flux:table.column>{{ __('Class') }}</flux:table.column>
                <flux:table.column>{{ __('Level') }}</flux:table.column>
                <flux:table.column class="hidden sm:table-cell">{{ __('Study program') }}</flux:table.column>
                <flux:table.column class="hidden sm:table-cell">{{ __('Mahasantri (active semester)') }}</flux:table.column>
                <flux:table.column></flux:table.column>
            </flux:table.columns>
            <flux:table.rows>
                @forelse ($this->classGroups as $classGroup)
                    <flux:table.row :key="$classGroup->id">
                        <flux:table.cell class="font-medium">{{ $classGroup->name }}</flux:table.cell>
                        <flux:table.cell>{{ $classGroup->level }}</flux:table.cell>
                        <flux:table.cell class="hidden sm:table-cell">{{ $classGroup->studyProgram->name }}</flux:table.cell>
                        <flux:table.cell class="hidden sm:table-cell">{{ $classGroup->active_students_count }}</flux:table.cell>
                        <flux:table.cell align="end">
                            <flux:button size="sm" variant="ghost" icon="pencil-square" wire:click="edit({{ $classGroup->id }})" :aria-label="__('Edit')" />
                            <flux:button size="sm" variant="ghost" icon="trash" wire:click="delete({{ $classGroup->id }})" wire:confirm="{{ __('Remove this class?') }}" :aria-label="__('Remove')" />
                        </flux:table.cell>
                    </flux:table.row>
                @empty
                    <flux:table.row>
                        <flux:table.cell colspan="5" class="text-center">{{ __('No classes yet.') }}</flux:table.cell>
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
            <flux:input wire:model="name" :label="__('Name')" placeholder="Marhalah 1 A" required />
            <flux:input wire:model="level" :label="__('Level (marhalah / tingkat)')" type="number" min="1" max="12" required />
            <div class="flex justify-end gap-2">
                <flux:modal.close><flux:button variant="ghost">{{ __('Cancel') }}</flux:button></flux:modal.close>
                <flux:button type="submit" variant="primary">{{ __('Save') }}</flux:button>
            </div>
        </form>
    </flux:modal>
</section>
