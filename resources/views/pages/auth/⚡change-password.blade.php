<?php

use App\Concerns\PasswordValidationRules;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Layout('layouts::auth'), Title('Change password')] class extends Component {
    use PasswordValidationRules;

    public string $current_password = '';
    public string $password = '';
    public string $password_confirmation = '';

    /**
     * Replace the temporary password with one chosen by the user.
     */
    public function changePassword(): void
    {
        $validated = $this->validate([
            'current_password' => $this->currentPasswordRules(),
            'password' => [...$this->passwordRules(), 'different:current_password'],
        ]);

        Auth::user()->forceFill([
            'password' => $validated['password'],
            'must_change_password' => false,
        ])->save();

        $this->redirectRoute('dashboard');
    }
}; ?>

<div class="flex flex-col gap-6">
    <x-auth-header
        :title="__('Change your password')"
        :description="__('You are using a temporary password. Choose a new password to continue.')"
    />

    <form wire:submit="changePassword" class="flex flex-col gap-6">
        <flux:input
            wire:model="current_password"
            :label="__('Temporary password')"
            type="password"
            required
            autofocus
            autocomplete="current-password"
            viewable
        />

        <flux:input
            wire:model="password"
            :label="__('New password')"
            type="password"
            required
            autocomplete="new-password"
            viewable
        />

        <flux:input
            wire:model="password_confirmation"
            :label="__('Confirm new password')"
            type="password"
            required
            autocomplete="new-password"
            viewable
        />

        <flux:button variant="primary" type="submit" class="w-full" data-test="change-password-button">
            {{ __('Save password') }}
        </flux:button>
    </form>

    <form method="POST" action="{{ route('logout') }}" class="text-center">
        @csrf
        <flux:button variant="ghost" size="sm" type="submit">{{ __('Log out') }}</flux:button>
    </form>
</div>
