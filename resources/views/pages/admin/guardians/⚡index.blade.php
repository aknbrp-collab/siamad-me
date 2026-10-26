<?php

use App\Models\Guardian;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('Wali santri')] class extends Component {
    use WithPagination;

    #[Url(except: '')]
    public string $search = '';

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    /**
     * @return LengthAwarePaginator<int, Guardian>
     */
    #[Computed]
    public function guardians(): LengthAwarePaginator
    {
        $term = '%'.mb_strtolower(trim($this->search)).'%';

        return Guardian::query()
            ->with(['user', 'students.user'])
            ->when(trim($this->search) !== '', fn (Builder $query) => $query->where(fn (Builder $query) => $query
                ->whereRaw('lower(code) like ?', [$term])
                ->orWhereHas('user', fn (Builder $query) => $query->whereRaw('lower(name) like ?', [$term]))
                ->orWhereHas('students', fn (Builder $query) => $query
                    ->whereRaw('lower(nim) like ?', [$term])
                    ->orWhereHas('user', fn (Builder $query) => $query->whereRaw('lower(name) like ?', [$term])))))
            ->orderByDesc('id')
            ->paginate(25);
    }
}; ?>

<section class="w-full">
    <x-page-header :title="__('Wali santri')" :subtitle="__('Guardian accounts log in with a generated code and can only view their own children.')">
        <x-slot:actions>
            <flux:button variant="primary" icon="plus" :href="route('admin.guardians.create')" wire:navigate>{{ __('Add wali santri') }}</flux:button>
        </x-slot:actions>
    </x-page-header>

    <flux:input wire:model.live.debounce.400ms="search" icon="magnifying-glass" :placeholder="__('Search code, guardian, NIM or mahasantri…')" class="mb-4 sm:max-w-sm" />

    <div class="overflow-x-auto">
        <flux:table class="table-stack" :paginate="$this->guardians">
            <flux:table.columns>
                <flux:table.column>{{ __('Code') }}</flux:table.column>
                <flux:table.column>{{ __('Name') }}</flux:table.column>
                <flux:table.column class="hidden md:table-cell">{{ __('Relationship') }}</flux:table.column>
                <flux:table.column>{{ __('Mahasantri') }}</flux:table.column>
            </flux:table.columns>
            <flux:table.rows>
                @forelse ($this->guardians as $guardian)
                    <flux:table.row :key="$guardian->id">
                        <flux:table.cell class="font-mono">{{ $guardian->code }}</flux:table.cell>
                        <flux:table.cell>
                            <flux:link :href="route('admin.guardians.edit', $guardian)" wire:navigate class="font-medium">{{ $guardian->user->name }}</flux:link>
                        </flux:table.cell>
                        <flux:table.cell class="hidden md:table-cell">{{ $guardian->relationship ?? '—' }}</flux:table.cell>
                        <flux:table.cell class="whitespace-normal">{{ $guardian->students->map(fn ($s) => $s->user->name)->join(', ') }}</flux:table.cell>
                    </flux:table.row>
                @empty
                    <flux:table.row>
                        <flux:table.cell colspan="4" class="text-center">{{ __('No wali santri found.') }}</flux:table.cell>
                    </flux:table.row>
                @endforelse
            </flux:table.rows>
        </flux:table>
    </div>
</section>
