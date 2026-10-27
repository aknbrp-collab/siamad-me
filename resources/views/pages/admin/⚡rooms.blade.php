<?php

use App\Enums\Gender;
use App\Models\Room;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Exam rooms')] class extends Component {
    public ?int $editingId = null;
    public string $name = '';
    public ?int $capacity = 30;
    public ?string $gender = null;
    public bool $isActive = true;

    /**
     * @return Collection<int, Room>
     */
    #[Computed]
    public function rooms(): Collection
    {
        return Room::query()->orderBy('gender')->orderBy('name')->get();
    }

    public function create(): void
    {
        $this->reset('editingId', 'name', 'gender');
        $this->capacity = 30;
        $this->isActive = true;
        $this->resetValidation();
        Flux::modal('room-form')->show();
    }

    public function edit(int $id): void
    {
        $room = Room::query()->findOrFail($id);

        $this->editingId = $room->id;
        $this->name = $room->name;
        $this->capacity = $room->capacity;
        $this->gender = $room->gender->value;
        $this->isActive = $room->is_active;
        $this->resetValidation();
        Flux::modal('room-form')->show();
    }

    public function save(): void
    {
        $data = $this->validate([
            'name' => ['required', 'string', 'max:50', Rule::unique('rooms', 'name')->ignore($this->editingId)],
            'capacity' => ['required', 'integer', 'between:1,1000'],
            'gender' => ['required', Rule::enum(Gender::class)],
            'isActive' => ['boolean'],
        ], [
            'gender.required' => __('Choose Putra or Putri.'),
        ]);

        Room::query()->updateOrCreate(['id' => $this->editingId], [
            'name' => trim($data['name']),
            'capacity' => $data['capacity'],
            'gender' => $data['gender'],
            'is_active' => $data['isActive'],
        ]);

        Flux::modal('room-form')->close();
        unset($this->rooms);
        Flux::toast(variant: 'success', text: __('Room saved.'));
    }
}; ?>

<section class="w-full">
    <x-page-header :title="__('Exam rooms')" :subtitle="__('Rooms for exam seating, each with its capacity and Putra or Putri: mahasantri are seated only in rooms of their own gender.')">
        <x-slot:actions>
            <flux:button variant="primary" icon="plus" wire:click="create">{{ __('Add room') }}</flux:button>
        </x-slot:actions>
    </x-page-header>

    <div class="overflow-x-auto">
        <flux:table class="table-stack">
            <flux:table.columns>
                <flux:table.column>{{ __('Room') }}</flux:table.column>
                <flux:table.column>{{ __('Putra / Putri') }}</flux:table.column>
                <flux:table.column align="end">{{ __('Capacity') }}</flux:table.column>
                <flux:table.column>{{ __('Status') }}</flux:table.column>
                <flux:table.column></flux:table.column>
            </flux:table.columns>
            <flux:table.rows>
                @forelse ($this->rooms as $room)
                    <flux:table.row :key="$room->id">
                        <flux:table.cell class="font-medium">{{ $room->name }}</flux:table.cell>
                        <flux:table.cell>{{ $room->gender->classLabel() }}</flux:table.cell>
                        <flux:table.cell align="end" class="tabular-nums">{{ $room->capacity }}</flux:table.cell>
                        <flux:table.cell>
                            <flux:badge size="sm" :color="$room->is_active ? 'green' : 'zinc'">{{ $room->is_active ? __('Active') : __('Inactive') }}</flux:badge>
                        </flux:table.cell>
                        <flux:table.cell align="end">
                            <flux:button size="sm" variant="ghost" icon="pencil-square" wire:click="edit({{ $room->id }})" :aria-label="__('Edit')" />
                        </flux:table.cell>
                    </flux:table.row>
                @empty
                    <flux:table.row>
                        <flux:table.cell colspan="5" class="text-center">{{ __('No rooms yet.') }}</flux:table.cell>
                    </flux:table.row>
                @endforelse
            </flux:table.rows>
        </flux:table>
    </div>

    <flux:modal name="room-form" class="w-full max-w-md">
        <form wire:submit="save" class="space-y-6">
            <flux:heading size="lg">{{ $editingId ? __('Edit room') : __('Add room') }}</flux:heading>
            <flux:input wire:model="name" :label="__('Name')" placeholder="R.101" required />
            <div class="grid gap-6 sm:grid-cols-2">
                <flux:input wire:model="capacity" :label="__('Capacity')" type="number" min="1" step="1" required />
                <flux:select wire:model="gender" :label="__('Putra / Putri')" :placeholder="__('Choose…')" required>
                    @foreach (Gender::cases() as $case)
                        <flux:select.option :value="$case->value">{{ $case->classLabel() }}</flux:select.option>
                    @endforeach
                </flux:select>
            </div>
            <flux:checkbox wire:model="isActive" :label="__('Active (can be used for exams)')" />
            <div class="flex justify-end gap-2">
                <flux:modal.close><flux:button variant="ghost">{{ __('Cancel') }}</flux:button></flux:modal.close>
                <flux:button type="submit" variant="primary">{{ __('Save') }}</flux:button>
            </div>
        </form>
    </flux:modal>
</section>
