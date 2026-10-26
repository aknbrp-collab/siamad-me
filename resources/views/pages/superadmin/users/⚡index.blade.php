<?php

use App\Enums\Role;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('Users & roles')] class extends Component {
    use WithPagination;

    #[Url(except: '')]
    public string $search = '';

    #[Url(except: '')]
    public string $role = '';

    #[Url(except: '')]
    public string $status = '';

    public function updated(string $property): void
    {
        if (in_array($property, ['search', 'role', 'status'], true)) {
            $this->resetPage();
        }
    }

    /**
     * @return LengthAwarePaginator<int, User>
     */
    #[Computed]
    public function users(): LengthAwarePaginator
    {
        $term = '%'.mb_strtolower(trim($this->search)).'%';

        return User::query()
            ->with('roles')
            ->when(trim($this->search) !== '', fn (Builder $query) => $query->where(fn (Builder $query) => $query
                ->where('username', 'like', $term)
                ->orWhereRaw('lower(name) like ?', [$term])
                ->orWhereRaw('lower(email) like ?', [$term])))
            ->when(Role::tryFrom($this->role), fn (Builder $query, Role $role) => $query->whereHas('roles', fn (Builder $query) => $query->where('role', $role)))
            ->when($this->status !== '', fn (Builder $query) => $query->where('is_active', $this->status === 'active'))
            ->orderBy('name')
            ->paginate(25);
    }
}; ?>

<section class="w-full">
    <x-page-header :title="__('Users & roles')" :subtitle="__('Every login account. Create staff accounts here and give anyone an extra staff role.')">
        <x-slot:actions>
            <flux:button variant="primary" icon="plus" :href="route('superadmin.users.create')" wire:navigate>{{ __('Add staff account') }}</flux:button>
        </x-slot:actions>
    </x-page-header>

    <div class="mb-4 flex flex-col gap-3 sm:flex-row">
        <flux:input wire:model.live.debounce.400ms="search" icon="magnifying-glass" :placeholder="__('Search login ID, name or email…')" class="sm:max-w-sm" />
        <flux:select wire:model.live="role" class="sm:max-w-52">
            <flux:select.option value="">{{ __('All roles') }}</flux:select.option>
            @foreach (Role::cases() as $option)
                <flux:select.option :value="$option->value">{{ $option->label() }}</flux:select.option>
            @endforeach
        </flux:select>
        <flux:select wire:model.live="status" class="sm:max-w-44">
            <flux:select.option value="">{{ __('Any status') }}</flux:select.option>
            <flux:select.option value="active">{{ __('Enabled') }}</flux:select.option>
            <flux:select.option value="inactive">{{ __('Disabled') }}</flux:select.option>
        </flux:select>
    </div>

    <div class="overflow-x-auto">
        <flux:table class="table-stack" :paginate="$this->users">
            <flux:table.columns>
                <flux:table.column>{{ __('Login ID') }}</flux:table.column>
                <flux:table.column>{{ __('Name') }}</flux:table.column>
                <flux:table.column>{{ __('Roles') }}</flux:table.column>
                <flux:table.column class="hidden md:table-cell">{{ __('Status') }}</flux:table.column>
                <flux:table.column class="hidden lg:table-cell">{{ __('Last login') }}</flux:table.column>
            </flux:table.columns>
            <flux:table.rows>
                @forelse ($this->users as $user)
                    <flux:table.row :key="$user->id">
                        <flux:table.cell class="font-mono">{{ $user->username }}</flux:table.cell>
                        <flux:table.cell>
                            <flux:link :href="route('superadmin.users.edit', $user)" wire:navigate class="font-medium">{{ $user->name }}</flux:link>
                            @if ($user->email)
                                <div class="text-xs text-zinc-500">{{ $user->email }}</div>
                            @endif
                        </flux:table.cell>
                        <flux:table.cell class="whitespace-normal">
                            <div class="flex flex-wrap gap-1">
                                @forelse ($user->roleList() as $held)
                                    <flux:badge size="sm" :color="$held->isStaff() || $held === Role::PaymentCollector ? 'indigo' : 'zinc'">
                                        {{ $held->label() }}{{ $held === Role::PaymentCollector && $user->collectorScope() ? ' · '.$user->collectorScope()->classLabel() : '' }}
                                    </flux:badge>
                                @empty
                                    <flux:badge size="sm" color="red">{{ __('No role') }}</flux:badge>
                                @endforelse
                            </div>
                        </flux:table.cell>
                        <flux:table.cell class="hidden md:table-cell">
                            <flux:badge size="sm" :color="$user->is_active ? 'green' : 'red'">{{ $user->is_active ? __('Enabled') : __('Disabled') }}</flux:badge>
                        </flux:table.cell>
                        <flux:table.cell class="hidden lg:table-cell">{{ $user->last_login_at?->diffForHumans() ?? __('Never') }}</flux:table.cell>
                    </flux:table.row>
                @empty
                    <flux:table.row>
                        <flux:table.cell colspan="5" class="text-center">{{ __('No users found.') }}</flux:table.cell>
                    </flux:table.row>
                @endforelse
            </flux:table.rows>
        </flux:table>
    </div>
</section>
