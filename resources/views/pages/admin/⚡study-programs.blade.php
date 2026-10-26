<?php

use App\Models\StudyProgram;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Study programs')] class extends Component {
    public ?int $editingId = null;
    public string $code = '';
    public string $name = '';

    /**
     * @return Collection<int, StudyProgram>
     */
    #[Computed]
    public function programs(): Collection
    {
        return StudyProgram::query()->withCount(['classGroups', 'subjects', 'students'])->orderBy('code')->get();
    }

    public function create(): void
    {
        $this->reset('editingId', 'code', 'name');
        $this->resetValidation();
        Flux::modal('program-form')->show();
    }

    public function edit(int $id): void
    {
        $program = StudyProgram::query()->findOrFail($id);

        $this->editingId = $program->id;
        $this->code = $program->code;
        $this->name = $program->name;
        $this->resetValidation();
        Flux::modal('program-form')->show();
    }

    public function save(): void
    {
        $data = $this->validate([
            'code' => ['required', 'string', 'max:20', 'alpha_dash', Rule::unique('study_programs', 'code')->ignore($this->editingId)],
            'name' => ['required', 'string', 'max:255'],
        ]);

        StudyProgram::query()->updateOrCreate(['id' => $this->editingId], $data);

        Flux::modal('program-form')->close();
        Flux::toast(variant: 'success', text: __('Study program saved.'));
    }

    public function delete(int $id): void
    {
        $program = StudyProgram::query()->withCount(['classGroups', 'subjects', 'students'])->findOrFail($id);

        if ($program->class_groups_count + $program->subjects_count + $program->students_count > 0) {
            Flux::toast(variant: 'danger', text: __('This study program is still used by classes, subjects or mahasantri.'));

            return;
        }

        $program->delete();
        Flux::toast(variant: 'success', text: __('Study program removed.'));
    }
}; ?>

<section class="w-full">
    <x-page-header :title="__('Study programs')" :subtitle="__('Programmes of study (prodi) offered by the institution.')">
        <x-slot:actions>
            <flux:button variant="primary" icon="plus" wire:click="create">{{ __('Add study program') }}</flux:button>
        </x-slot:actions>
    </x-page-header>

    <div class="overflow-x-auto">
        <flux:table class="table-stack">
            <flux:table.columns>
                <flux:table.column>{{ __('Code') }}</flux:table.column>
                <flux:table.column>{{ __('Name') }}</flux:table.column>
                <flux:table.column class="hidden sm:table-cell">{{ __('Classes') }}</flux:table.column>
                <flux:table.column class="hidden sm:table-cell">{{ __('Subjects') }}</flux:table.column>
                <flux:table.column class="hidden sm:table-cell">{{ __('Mahasantri') }}</flux:table.column>
                <flux:table.column></flux:table.column>
            </flux:table.columns>
            <flux:table.rows>
                @forelse ($this->programs as $program)
                    <flux:table.row :key="$program->id">
                        <flux:table.cell class="font-mono">{{ $program->code }}</flux:table.cell>
                        <flux:table.cell class="font-medium">{{ $program->name }}</flux:table.cell>
                        <flux:table.cell class="hidden sm:table-cell">{{ $program->class_groups_count }}</flux:table.cell>
                        <flux:table.cell class="hidden sm:table-cell">{{ $program->subjects_count }}</flux:table.cell>
                        <flux:table.cell class="hidden sm:table-cell">{{ $program->students_count }}</flux:table.cell>
                        <flux:table.cell align="end">
                            <flux:button size="sm" variant="ghost" icon="pencil-square" wire:click="edit({{ $program->id }})" :aria-label="__('Edit')" />
                            <flux:button size="sm" variant="ghost" icon="trash" wire:click="delete({{ $program->id }})" wire:confirm="{{ __('Remove this study program?') }}" :aria-label="__('Remove')" />
                        </flux:table.cell>
                    </flux:table.row>
                @empty
                    <flux:table.row>
                        <flux:table.cell colspan="6" class="text-center">{{ __('No study programs yet.') }}</flux:table.cell>
                    </flux:table.row>
                @endforelse
            </flux:table.rows>
        </flux:table>
    </div>

    <flux:modal name="program-form" class="w-full max-w-md">
        <form wire:submit="save" class="space-y-6">
            <flux:heading size="lg">{{ $editingId ? __('Edit study program') : __('Add study program') }}</flux:heading>
            <flux:input wire:model="code" :label="__('Code')" :description:trailing="__('Used in import files, e.g. MA.')" required />
            <flux:input wire:model="name" :label="__('Name')" required />
            <div class="flex justify-end gap-2">
                <flux:modal.close><flux:button variant="ghost">{{ __('Cancel') }}</flux:button></flux:modal.close>
                <flux:button type="submit" variant="primary">{{ __('Save') }}</flux:button>
            </div>
        </form>
    </flux:modal>
</section>
