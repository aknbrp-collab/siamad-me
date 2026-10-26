<?php

use App\Actions\UpdateUserProfile;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Profile settings')] class extends Component {
    public string $name = '';
    public ?string $email = null;

    /**
     * Mount the component.
     */
    public function mount(): void
    {
        $this->name = Auth::user()->name;
        $this->email = Auth::user()->email;
    }

    /**
     * Update the profile information for the currently authenticated user.
     */
    public function updateProfileInformation(UpdateUserProfile $updateUserProfile): void
    {
        $updateUserProfile->handle(Auth::user(), [
            'name' => $this->name,
            'email' => $this->email,
        ]);

        Flux::toast(variant: 'success', text: __('Profile updated.'));
    }
}; ?>

<section class="w-full">
    @include('partials.settings-heading')

    <flux:heading level="2" class="sr-only">{{ __('Profile settings') }}</flux:heading>

    <x-pages::settings.layout :heading="__('Profile')" :subheading="__('Update your name and email address')">
        <form wire:submit="updateProfileInformation" class="my-6 w-full space-y-6">
            <flux:input
                :value="Auth::user()->username"
                :label="__('ID number')"
                :description:trailing="__('Your login ID can only be changed by the academic office.')"
                readonly
                disabled
            />

            <flux:input wire:model="name" :label="__('Name')" type="text" required autofocus autocomplete="name" />

            <flux:input
                wire:model="email"
                :label="__('Email')"
                :description:trailing="__('Optional. Used to reset your password yourself.')"
                type="email"
                autocomplete="email"
            />

            <div class="flex items-center gap-4">
                <div class="flex items-center justify-end">
                    <flux:button variant="primary" type="submit" class="w-full" data-test="update-profile-button">
                        {{ __('Save') }}
                    </flux:button>
                </div>
            </div>
        </form>
    </x-pages::settings.layout>
</section>
