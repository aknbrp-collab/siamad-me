<?php

use App\Actions\People\CreateTeacher;
use App\Actions\People\UpdateTeacher;
use App\Enums\Gender;
use App\Enums\TeacherStatus;
use App\Livewire\PersonForm;
use App\Models\Teacher;
use App\Models\User;
use Flux\Flux;
use Livewire\Attributes\Title;
use Livewire\WithFileUploads;

new #[Title('Ustadz')] class extends PersonForm {
    use WithFileUploads;

    public ?Teacher $teacher = null;

    // Field names match the action's validation keys so errors show inline.
    public string $nidm = '';
    public string $name = '';
    public ?string $email = null;
    public ?string $nik = null;
    public ?string $title = null;
    public ?string $gender = null;
    public ?string $birth_place = null;
    public ?string $birth_date = null;
    public ?string $address = null;
    public ?string $phone = null;
    public ?string $expertise = null;
    public ?string $scopus_id = null;
    public ?string $google_scholar_url = null;
    public string $status = 'active';
    public $photo = null;

    public function mount(?Teacher $teacher = null): void
    {
        if ($teacher?->exists) {
            $this->teacher = $teacher->load(['user', 'documents']);
            $this->fill([
                ...$teacher->only(['nidm', 'nik', 'title', 'birth_place', 'address', 'phone', 'expertise', 'scopus_id', 'google_scholar_url']),
                'name' => $teacher->user->name,
                'email' => $teacher->user->email,
                'gender' => $teacher->gender->value,
                'birth_date' => $teacher->birth_date?->format('Y-m-d'),
                'status' => $teacher->status->value,
            ]);
        }
    }

    protected function accountUser(): ?User
    {
        return $this->teacher?->user;
    }

    protected function editRoute(): ?string
    {
        return $this->teacher ? route('admin.teachers.edit', $this->teacher) : null;
    }

    public function save(CreateTeacher $createTeacher, UpdateTeacher $updateTeacher): void
    {
        $this->resetErrorBag();

        $input = [
            'nidm' => trim($this->nidm),
            'name' => trim($this->name),
            'email' => $this->email ?: null,
            'nik' => $this->nik ?: null,
            'title' => $this->title ?: null,
            'gender' => $this->gender,
            'birth_place' => $this->birth_place ?: null,
            'birth_date' => $this->birth_date ?: null,
            'address' => $this->address ?: null,
            'phone' => $this->phone ?: null,
            'expertise' => $this->expertise ?: null,
            'scopus_id' => $this->scopus_id ?: null,
            'google_scholar_url' => $this->google_scholar_url ?: null,
            'photo' => $this->photo,
        ];

        if ($this->teacher === null) {
            $account = $createTeacher->handle($input);
            $this->teacher = $account->model->load(['user', 'documents']);
            $this->photo = null;
            $this->justCreated = true;
            $this->showCredentials($account);

            return;
        }

        $updateTeacher->handle($this->teacher, [...$input, 'status' => $this->status]);

        $this->teacher->refresh()->load(['user', 'documents']);
        $this->photo = null;
        Flux::toast(variant: 'success', text: __('Ustadz saved.'));
    }

}; ?>

<section class="w-full">
    <x-page-header :title="$teacher ? $teacher->displayName() : __('Add ustadz')" :subtitle="$teacher ? __('NIDM :nidm', ['nidm' => $teacher->nidm]) : __('A login is created with the NIDM as ID and a temporary password.')">
        <x-slot:actions>
            <flux:button size="sm" variant="ghost" icon="arrow-left" :href="route('admin.teachers.index')" wire:navigate>{{ __('Ustadz') }}</flux:button>
        </x-slot:actions>
    </x-page-header>

    <div class="grid gap-6 xl:grid-cols-[1fr_20rem]">
        <form wire:submit="save" class="space-y-8">
            <flux:fieldset>
                <flux:legend>{{ __('Identity & login') }}</flux:legend>
                <div class="grid gap-6 sm:grid-cols-2">
                    <flux:input wire:model="nidm" :label="__('NIDM')" required />
                    <flux:input wire:model="nik" :label="__('NIK')" :description:trailing="__('16 digits, optional.')" />
                    <flux:input wire:model="name" :label="__('Full name')" required />
                    <flux:input wire:model="title" :label="__('Title (gelar)')" placeholder="Lc., M.A." />
                    <flux:input wire:model="email" :label="__('Email')" type="email" :description:trailing="__('Optional.')" />
                    <flux:select wire:model="gender" :label="__('Gender')" :placeholder="__('Choose…')" required>
                        @foreach (Gender::cases() as $case)
                            <flux:select.option :value="$case->value">{{ $case->label() }}</flux:select.option>
                        @endforeach
                    </flux:select>
                    @if ($teacher)
                        <flux:select wire:model="status" :label="__('Status')">
                            @foreach (TeacherStatus::cases() as $case)
                                <flux:select.option :value="$case->value">{{ $case->label() }}</flux:select.option>
                            @endforeach
                        </flux:select>
                    @endif
                </div>
            </flux:fieldset>

            <flux:fieldset>
                <flux:legend>{{ __('Personal') }}</flux:legend>
                <div class="grid gap-6 sm:grid-cols-2">
                    <flux:input wire:model="birth_place" :label="__('Birth place')" />
                    <flux:input wire:model="birth_date" :label="__('Birth date')" type="date" />
                    <flux:input wire:model="phone" :label="__('Phone')" />
                    <div>
                        <flux:label>{{ __('Photo') }}</flux:label>
                        <div class="mt-2 flex items-center gap-3">
                            @if ($photo && method_exists($photo, 'temporaryUrl'))
                                <img src="{{ $photo->temporaryUrl() }}" alt="" class="size-12 rounded-full object-cover">
                            @elseif ($teacher?->photo_path)
                                <img src="{{ route('files.show', ['teacher-photo', $teacher->id]) }}" alt="" class="size-12 rounded-full object-cover">
                            @endif
                            <input type="file" wire:model="photo" accept="image/*" class="text-sm file:me-3 file:rounded-md file:border-0 file:bg-zinc-100 file:px-3 file:py-1.5 dark:file:bg-zinc-700">
                        </div>
                        <flux:error name="photo" />
                    </div>
                    <flux:textarea wire:model="address" :label="__('Address')" rows="2" class="sm:col-span-2" />
                </div>
            </flux:fieldset>

            <flux:fieldset>
                <flux:legend>{{ __('Academic profile') }}</flux:legend>
                <div class="grid gap-6 sm:grid-cols-2">
                    <flux:input wire:model="expertise" :label="__('Expertise (keahlian)')" class="sm:col-span-2" />
                    <flux:input wire:model="scopus_id" :label="__('Scopus author ID')" />
                    <flux:input wire:model="google_scholar_url" :label="__('Google Scholar profile URL')" type="url" />
                </div>
            </flux:fieldset>

            <flux:button type="submit" variant="primary" data-test="save-teacher">{{ $teacher ? __('Save') : __('Create ustadz') }}</flux:button>
        </form>

        @if ($teacher)
            <div class="space-y-6">
                <x-account-panel :user="$teacher->user" />

                <flux:card class="space-y-3">
                    <div class="flex items-center justify-between">
                        <flux:heading>{{ __('Documents') }}</flux:heading>
                        <flux:link :href="route('admin.documents', ['owner' => 'teachers', 'search' => $teacher->nidm])" wire:navigate class="text-sm">{{ __('Review') }}</flux:link>
                    </div>
                    @foreach ($teacher->documents as $document)
                        <div class="flex items-center justify-between text-sm">
                            <span>{{ $document->type->label() }}</span>
                            <flux:badge size="sm" :color="$document->status->color()">{{ $document->status->label() }}</flux:badge>
                        </div>
                    @endforeach
                </flux:card>
            </div>
        @endif
    </div>

    @include('partials.credentials-modal')
</section>
