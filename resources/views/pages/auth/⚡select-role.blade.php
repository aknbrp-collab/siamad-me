<?php

use App\Enums\Role;
use App\Support\ActiveRole;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Layout('layouts::auth'), Title('Choose a role')] class extends Component {
    /**
     * @return list<Role>
     */
    #[Computed]
    public function roles(): array
    {
        return Auth::user()->roleList();
    }

    /**
     * Act as the chosen role and go to its dashboard.
     */
    public function choose(string $role): void
    {
        $chosen = Role::tryFrom($role);

        abort_unless($chosen !== null && Auth::user()->hasRole($chosen), 403);

        ActiveRole::set($chosen);

        $this->redirectRoute($chosen->homeRoute());
    }
}; ?>

<div class="flex flex-col gap-6">
    <x-auth-header
        :title="__('Choose a role')"
        :description="__('Your account has more than one role. Which one do you want to use now?')"
    />

    <div class="flex flex-col gap-3">
        @foreach ($this->roles as $role)
            <button
                type="button"
                wire:click="choose('{{ $role->value }}')"
                wire:key="role-{{ $role->value }}"
                data-test="choose-role-{{ $role->value }}"
                @class([
                    'flex w-full items-center gap-4 rounded-lg border p-4 text-start transition',
                    'border-zinc-200 hover:border-zinc-400 hover:bg-zinc-50 dark:border-zinc-700 dark:hover:border-zinc-500 dark:hover:bg-zinc-800',
                    'ring-2 ring-accent' => ActiveRole::get() === $role,
                ])
            >
                <flux:icon :icon="$role->icon()" class="size-6 shrink-0 text-zinc-500 dark:text-zinc-400" />
                <span class="flex flex-col">
                    <span class="font-medium text-zinc-900 dark:text-white">{{ $role->label() }}</span>
                    <span class="text-sm text-zinc-500 dark:text-zinc-400">{{ $role->description() }}</span>
                </span>
            </button>
        @endforeach
    </div>

    <form method="POST" action="{{ route('logout') }}" class="text-center">
        @csrf
        <flux:button variant="ghost" size="sm" type="submit">{{ __('Log out') }}</flux:button>
    </form>
</div>
