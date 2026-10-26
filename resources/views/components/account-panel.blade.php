{{-- Used inside components with the ManagesAccount trait (its buttons call that component). --}}
@props(['user'])

<flux:card class="space-y-4">
    <flux:heading>{{ __('Login account') }}</flux:heading>

    <dl class="grid grid-cols-[auto_1fr] gap-x-4 gap-y-2 text-sm">
        <dt class="text-zinc-500">{{ __('Login ID') }}</dt>
        <dd class="font-mono">{{ $user->username }}</dd>
        <dt class="text-zinc-500">{{ __('Status') }}</dt>
        <dd>
            <flux:badge size="sm" :color="$user->is_active ? 'green' : 'red'">{{ $user->is_active ? __('Enabled') : __('Disabled') }}</flux:badge>
            @if ($user->must_change_password)
                <flux:badge size="sm" color="amber">{{ __('Temporary password') }}</flux:badge>
            @endif
        </dd>
        <dt class="text-zinc-500">{{ __('Last login') }}</dt>
        <dd>{{ $user->last_login_at?->diffForHumans() ?? __('Never') }}</dd>
    </dl>

    @if ($user->accountManageableBy(\App\Support\ActiveRole::get()))
        <div class="flex flex-wrap gap-2">
            <flux:button size="sm" icon="key" wire:click="resetPassword" wire:confirm="{{ __('Create a new temporary password? The current password stops working.') }}">
                {{ __('Reset password') }}
            </flux:button>
            <flux:button size="sm" :variant="$user->is_active ? 'danger' : 'primary'" wire:click="toggleActive"
                :icon="$user->is_active ? 'no-symbol' : 'check-circle'">
                {{ $user->is_active ? __('Disable login') : __('Enable login') }}
            </flux:button>
        </div>
    @else
        <flux:text class="text-sm">{{ __('This account holds a staff role. Only a Superadmin can reset its password or disable it.') }}</flux:text>
    @endif
</flux:card>
