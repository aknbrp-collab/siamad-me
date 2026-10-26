<?php

use App\Models\StudyProgram;
use App\Models\Subject;
use Flux\Flux;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('Subjects')] class extends Component {
    use WithPagination;

    #[Url(except: '')]
    public string $search = '';

    public ?int $editingId = null;
    public ?int $studyProgramId = null;
    public string $code = '';
    public string $name = '';
    public ?string $kitab = null;
    public ?int $credits = 2;
    public bool $isActive = true;

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    /**
     * @return LengthAwarePaginator<int, Subject>
     */
    #[Computed]
    public function subjects(): LengthAwarePaginator
    {
        $term = '%'.mb_strtolower($this->search).'%';

        return Subject::query()
            ->with('studyProgram')
            ->when($this->search !== '', fn (Builder $query) => $query->where(fn (Builder $query) => $query
                ->whereRaw('lower(code) like ?', [$term])
                ->orWhereRaw('lower(name) like ?', [$term])
                ->orWhereRaw('lower(kitab) like ?', [$term])))
            ->orderBy('code')
            ->paginate(25);
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
        $this->reset('editingId', 'code', 'name', 'kitab');
        $this->credits = 2;
        $this->isActive = true;
        $this->studyProgramId = $this->programs->first()?->id;
        $this->resetValidation();
        Flux::modal('subject-form')->show();
    }

    public function edit(int $id): void
    {
        $subject = Subject::query()->findOrFail($id);

        $this->editingId = $subject->id;
        $this->studyProgramId = $subject->study_program_id;
        $this->code = $subject->code;
        $this->name = $subject->name;
        $this->kitab = $subject->kitab;
        $this->credits = $subject->credits;
        $this->isActive = $subject->is_active;
        $this->resetValidation();
        Flux::modal('subject-form')->show();
    }

    public function save(): void
    {
        $data = $this->validate([
            'studyProgramId' => ['required', 'integer', Rule::exists('study_programs', 'id')->whereNull('deleted_at')],
            'code' => ['required', 'string', 'max:20', 'alpha_dash', Rule::unique('subjects', 'code')->ignore($this->editingId)],
            'name' => ['required', 'string', 'max:255'],
            'kitab' => ['nullable', 'string', 'max:255'],
            'credits' => ['required', 'integer', 'between:1,12'],
            'isActive' => ['boolean'],
        ]);

        Subject::query()->updateOrCreate(['id' => $this->editingId], [
            'study_program_id' => $data['studyProgramId'],
            'code' => $data['code'],
            'name' => $data['name'],
            'kitab' => $data['kitab'] ?: null,
            'credits' => $data['credits'],
            'is_active' => $data['isActive'],
        ]);

        Flux::modal('subject-form')->close();
        Flux::toast(variant: 'success', text: __('Subject saved. Existing course offerings keep their snapshot.'));
    }
}; ?>

<section class="w-full">
    <x-page-header :title="__('Subjects')" :subtitle="__('Mata kuliah / kitab / dars in the curriculum. Deactivate a subject instead of deleting it.')">
        <x-slot:actions>
            <flux:button variant="primary" icon="plus" wire:click="create" :disabled="$this->programs->isEmpty()">{{ __('Add subject') }}</flux:button>
        </x-slot:actions>
    </x-page-header>

    <flux:input wire:model.live.debounce.400ms="search" icon="magnifying-glass" :placeholder="__('Search code, name or kitab…')" class="mb-4 sm:max-w-sm" />

    <div class="overflow-x-auto">
        <flux:table class="table-stack" :paginate="$this->subjects">
            <flux:table.columns>
                <flux:table.column>{{ __('Code') }}</flux:table.column>
                <flux:table.column>{{ __('Name') }}</flux:table.column>
                <flux:table.column class="hidden md:table-cell">{{ __('Kitab') }}</flux:table.column>
                <flux:table.column>{{ __('SKS') }}</flux:table.column>
                <flux:table.column class="hidden sm:table-cell">{{ __('Status') }}</flux:table.column>
                <flux:table.column></flux:table.column>
            </flux:table.columns>
            <flux:table.rows>
                @forelse ($this->subjects as $subject)
                    <flux:table.row :key="$subject->id">
                        <flux:table.cell class="font-mono">{{ $subject->code }}</flux:table.cell>
                        <flux:table.cell class="font-medium">{{ $subject->name }}</flux:table.cell>
                        <flux:table.cell class="hidden md:table-cell">{{ $subject->kitab ?? '—' }}</flux:table.cell>
                        <flux:table.cell>{{ $subject->credits }}</flux:table.cell>
                        <flux:table.cell class="hidden sm:table-cell">
                            <flux:badge size="sm" :color="$subject->is_active ? 'green' : 'zinc'">{{ $subject->is_active ? __('Active') : __('Inactive') }}</flux:badge>
                        </flux:table.cell>
                        <flux:table.cell align="end">
                            <flux:button size="sm" variant="ghost" icon="pencil-square" wire:click="edit({{ $subject->id }})" :aria-label="__('Edit')" />
                        </flux:table.cell>
                    </flux:table.row>
                @empty
                    <flux:table.row>
                        <flux:table.cell colspan="6" class="text-center">{{ __('No subjects found.') }}</flux:table.cell>
                    </flux:table.row>
                @endforelse
            </flux:table.rows>
        </flux:table>
    </div>

    <flux:modal name="subject-form" class="w-full max-w-lg">
        <form wire:submit="save" class="space-y-6">
            <flux:heading size="lg">{{ $editingId ? __('Edit subject') : __('Add subject') }}</flux:heading>
            <flux:select wire:model="studyProgramId" :label="__('Study program')" required>
                @foreach ($this->programs as $program)
                    <flux:select.option :value="$program->id">{{ $program->code }} — {{ $program->name }}</flux:select.option>
                @endforeach
            </flux:select>
            <div class="grid gap-6 sm:grid-cols-3">
                <flux:input wire:model="code" :label="__('Code')" required />
                <flux:input wire:model="name" :label="__('Name')" required class="sm:col-span-2" />
            </div>
            <flux:input wire:model="kitab" :label="__('Kitab (reference text)')" />
            <div class="grid items-end gap-6 sm:grid-cols-2">
                <flux:input wire:model="credits" :label="__('Credits (SKS)')" type="number" min="1" max="12" required />
                <flux:switch wire:model="isActive" :label="__('Active')" />
            </div>
            <div class="flex justify-end gap-2">
                <flux:modal.close><flux:button variant="ghost">{{ __('Cancel') }}</flux:button></flux:modal.close>
                <flux:button type="submit" variant="primary">{{ __('Save') }}</flux:button>
            </div>
        </form>
    </flux:modal>
</section>
