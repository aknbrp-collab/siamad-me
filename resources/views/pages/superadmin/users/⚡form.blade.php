<?php

use App\Actions\People\ManageUserAccounts;
use App\Enums\Gender;
use App\Enums\Role;
use App\Livewire\PersonForm;
use App\Models\User;
use Flux\Flux;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;

new #[Title('Users & roles')] class extends PersonForm {
    public ?User $user = null;

    public string $username = '';
    public string $name = '';
    public ?string $email = null;

    /** @var list<string> Staff roles (values of Role::staff()). */
    public array $roles = [];

    /** Petugas Pembayaran scope: '' (not a collector), 'male' or 'female'. */
    public string $collectorScope = '';

    public function mount(?User $user = null): void
    {
        if ($user?->exists) {
            $this->user = $user->load(['roles', 'student', 'teacher', 'guardian']);
            $this->username = $user->username;
            $this->name = $user->name;
            $this->email = $user->email;
            $this->roles = $this->heldStaffRoles();
            $this->collectorScope = $user->collectorScope()->value ?? '';
        }
    }

    /**
     * @return list<string>
     */
    private function heldStaffRoles(): array
    {
        return array_values(array_map(
            fn (Role $role) => $role->value,
            array_filter($this->user->roleList(), fn (Role $role) => $role->isStaff()),
        ));
    }

    /**
     * Link and label of the person's mahasantri / ustadz / wali santri page.
     *
     * @return array{label: string, url: string}|null
     */
    #[Computed]
    public function profile(): ?array
    {
        return match (true) {
            $this->user?->student !== null => ['label' => __('Mahasantri profile'), 'url' => route('admin.students.edit', $this->user->student)],
            $this->user?->teacher !== null => ['label' => __('Ustadz profile'), 'url' => route('admin.teachers.edit', $this->user->teacher)],
            $this->user?->guardian !== null => ['label' => __('Wali santri profile'), 'url' => route('admin.guardians.edit', $this->user->guardian)],
            default => null,
        };
    }

    /**
     * Mahasantri and wali santri accounts can not hold staff roles.
     */
    #[Computed]
    public function canHoldStaffRoles(): bool
    {
        return $this->user === null || ! $this->user->hasRole(Role::Student, Role::Guardian);
    }

    protected function accountUser(): ?User
    {
        return $this->user;
    }

    protected function editRoute(): ?string
    {
        return $this->user ? route('superadmin.users.edit', $this->user) : null;
    }

    public function save(ManageUserAccounts $accounts): void
    {
        $this->resetErrorBag();

        $input = ['username' => trim($this->username), 'name' => trim($this->name), 'email' => $this->email ?: null];

        if ($this->user === null) {
            $account = $accounts->create([...$input, 'roles' => $this->roles]);

            $this->user = $account->model->load(['roles', 'student', 'teacher', 'guardian']);
            $this->justCreated = true;
            $this->showCredentials($account);

            return;
        }

        $accounts->update($this->user, $input);
        $this->user->refresh();
        Flux::toast(variant: 'success', text: __('Account saved.'));
    }

    public function saveCollector(ManageUserAccounts $accounts): void
    {
        $this->resetErrorBag();
        abort_if($this->user === null, 404);

        $accounts->setCollector($this->user, Gender::tryFrom($this->collectorScope));

        $this->user->unsetRelation('roles');
        Flux::toast(variant: 'success', text: __('Roles saved. They apply from the user\'s next request.'));
    }

    public function saveRoles(ManageUserAccounts $accounts): void
    {
        $this->resetErrorBag();
        abort_if($this->user === null, 404);

        $accounts->syncStaffRoles($this->user, array_values($this->roles), auth()->user());

        $this->user->unsetRelation('roles');
        $this->roles = $this->heldStaffRoles();
        Flux::toast(variant: 'success', text: __('Roles saved. They apply from the user\'s next request.'));
    }
}; ?>

<section class="w-full">
    <x-page-header :title="$user ? $user->name : __('Add staff account')" :subtitle="$user ? __('Login ID :id', ['id' => $user->username]) : __('A temporary password is generated; the user changes it at first login.')">
        <x-slot:actions>
            <flux:button size="sm" variant="ghost" icon="arrow-left" :href="route('superadmin.users.index')" wire:navigate>{{ __('Users & roles') }}</flux:button>
        </x-slot:actions>
    </x-page-header>

    <div class="grid gap-6 xl:grid-cols-[1fr_20rem]">
        <div class="space-y-8">
            @if ($this->profile)
                <flux:callout icon="information-circle">
                    <flux:callout.text>{{ __('Name, login ID and biodata of this person are edited on their profile page. Here you can give them extra staff roles.') }}</flux:callout.text>
                    <x-slot name="actions">
                        <flux:button size="sm" :href="$this->profile['url']" wire:navigate>{{ $this->profile['label'] }}</flux:button>
                    </x-slot>
                </flux:callout>
            @else
                <form wire:submit="save" class="space-y-6">
                    <flux:fieldset>
                        <flux:legend>{{ __('Account') }}</flux:legend>
                        <div class="grid gap-6 sm:grid-cols-2">
                            <flux:input wire:model="username" :label="__('Login ID')" :description:trailing="__('Letters, digits, dot, dash or underscore.')" required />
                            <flux:input wire:model="name" :label="__('Full name')" required />
                            <flux:input wire:model="email" :label="__('Email')" type="email" :description:trailing="__('Optional.')" />
                        </div>
                    </flux:fieldset>

                    @unless ($user)
                        <flux:checkbox.group wire:model="roles" :label="__('Roles')">
                            @foreach (Role::staff() as $role)
                                <flux:checkbox :value="$role->value" :label="$role->label()" :description="$role->description()" />
                            @endforeach
                        </flux:checkbox.group>
                        <flux:error name="roles" />
                    @endunless

                    <flux:button type="submit" variant="primary" data-test="save-user">{{ $user ? __('Save') : __('Create account') }}</flux:button>
                </form>
            @endif

            @if ($user)
                <form wire:submit="saveRoles" class="space-y-6">
                    <flux:fieldset>
                        <flux:legend>{{ __('Roles') }}</flux:legend>

                        @php($profileRoles = array_filter($user->roleList(), fn ($role) => ! $role->isStaff()))
                        @if ($profileRoles !== [])
                            <div class="mb-4 flex flex-wrap items-center gap-2">
                                @foreach ($profileRoles as $role)
                                    <flux:badge size="sm">{{ $role->label() }}</flux:badge>
                                @endforeach
                                <flux:text class="text-sm">{{ __('Comes with the profile.') }}</flux:text>
                            </div>
                        @endif

                        @if ($this->canHoldStaffRoles)
                            <flux:checkbox.group wire:model="roles">
                                @foreach (Role::staff() as $role)
                                    <flux:checkbox :value="$role->value" :label="$role->label()" :description="$role->description()"
                                        :disabled="$role === Role::Superadmin && $user->is(auth()->user())" />
                                @endforeach
                            </flux:checkbox.group>
                        @else
                            <flux:text class="text-sm">{{ __('Mahasantri and wali santri accounts can not hold staff roles.') }}</flux:text>
                        @endif
                        <flux:error name="roles" />
                    </flux:fieldset>

                    @if ($this->canHoldStaffRoles)
                        <flux:button type="submit" variant="primary" data-test="save-roles">{{ __('Save roles') }}</flux:button>
                    @endif
                </form>
            @endif

            @if ($user && ! $user->hasRole(Role::Guardian))
                <form wire:submit="saveCollector" class="space-y-4" data-test="collector-form">
                    <flux:fieldset>
                        <flux:legend>{{ __('Payment Collector') }}</flux:legend>
                        <flux:text class="mb-3 text-sm">{{ __('Records payments for the bills of putra or putri mahasantri only, and hands the cash over. Cannot verify payments. A mahasantri may hold this role.') }}</flux:text>
                        <flux:select wire:model="collectorScope" class="sm:max-w-64">
                            <flux:select.option value="">{{ __('Not a payment collector') }}</flux:select.option>
                            @foreach (Gender::cases() as $gender)
                                <flux:select.option :value="$gender->value">{{ __('Payment collector for :gender', ['gender' => $gender->classLabel()]) }}</flux:select.option>
                            @endforeach
                        </flux:select>
                        <flux:error name="collector" />
                    </flux:fieldset>
                    <flux:button type="submit" variant="primary" data-test="save-collector">{{ __('Save') }}</flux:button>
                </form>
            @endif
        </div>

        @if ($user)
            <div>
                <x-account-panel :user="$user" />
            </div>
        @endif
    </div>

    @include('partials.credentials-modal')
</section>
